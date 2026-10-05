<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Enums\TransactionType;
use PHPinnacle\Minos\Events\TransactionCreated;
use PHPinnacle\Minos\Events\TransactionStatusChanged;
use PHPinnacle\Minos\Events\TransactionUpdated;
use PHPinnacle\Minos\Models\Adjustment;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\IntentLine;
use PHPinnacle\Minos\Models\Payer;
use PHPinnacle\Minos\Models\PaymentMethod;
use PHPinnacle\Minos\Models\Source;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Payments\Bank;
use PHPinnacle\Minos\Payments\BePaid;
use PHPinnacle\Minos\Payments\Card;
use PHPinnacle\Minos\Payments\Cash;
use PHPinnacle\Money\Money;
use Tests\TestCase;

uses(TestCase::class);

class MinosTransactionSubject extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}

function minos_transaction_intent(?PaymentMethod $method = null, int $amount = 1000): Intent
{
    if ($method === null) {
        $method = new Cash()->define();
        $method->save();
    }

    return new Intent(
        id: (string) Str::uuid(),
        number: 'PAY-001',
        description: 'First installment',
        method: $method,
        source: new Source('1', MinosTransactionSubject::class, 'ORDER-001'),
        payer: new Payer('2', MinosTransactionSubject::class),
        instrument: null,
        lines: [new IntentLine('Item', 1, new Money($amount, 'USD'))],
    );
}

beforeEach(function () {
    (require __DIR__ . '/../../database/migrations/create_minos_tables.php')->up();
    Schema::create('minos_transaction_subjects', function (Blueprint $table) {
        $table->id();
    });
    MinosTransactionSubject::query()->create(['id' => 1]);
    MinosTransactionSubject::query()->create(['id' => 2]);
    Http::preventStrayRequests();
});

it('persists a transaction with application-owned source and payer models', function () {
    $intent = minos_transaction_intent();
    $transaction = Transaction::payment($intent)->fresh();

    expect($transaction->id)
        ->toBe($intent->id)
        ->and($transaction->status)
        ->toBe(TransactionStatus::Pending)
        ->and($transaction->amount->eq($intent->total()))
        ->toBeTrue()
        ->and($transaction->method->is($intent->method))
        ->toBeTrue()
        ->and($transaction->source->getKey())
        ->toBe(1)
        ->and($transaction->payer->getKey())
        ->toBe(2)
        ->and(Transaction::forSource($intent->source)->sole()->is($transaction))
        ->toBeTrue()
        ->and(Transaction::forSource(new Source('2', MinosTransactionSubject::class))->count())
        ->toBe(0)
        ->and($transaction->balanceImpact()->isZero())
        ->toBeTrue();
});

it('requires explicit confirmation for manual payments', function (string $providerClass) {
    $provider = new $providerClass;
    $method = $provider->define(['name' => 'Bank transfer', 'account' => 'test']);
    $method->save();
    $transaction = Transaction::payment(minos_transaction_intent($method));

    expect($transaction->status)->toBe(TransactionStatus::Pending);
    $transaction->handle(Continuation::success());
    expect($transaction->balanceImpact()->amount)->toBe(1000);

    $refund = $transaction->refund('REF-001', new Money(250, 'USD'), 'Returned item');
    expect($refund->balanceImpact()->isZero())->toBeTrue();
    $refund->handle(Continuation::success());
    expect($refund->balanceImpact()->amount)->toBe(-250);
    Http::assertNothingSent();
})->with([Cash::class, Bank::class, Card::class]);

it('calculates persisted amounts from line quantities and ordered adjustments', function () {
    $base = minos_transaction_intent();
    $line = new IntentLine('First item', 3, new Money(125, 'USD'));
    $intent = new Intent(
        id: $base->id,
        number: $base->number,
        description: $base->description,
        method: $base->method,
        source: $base->source,
        payer: $base->payer,
        instrument: null,
        lines: [$line, new IntentLine('Second item', 2, new Money(90, 'USD'))],
        adjustments: [
            Adjustment::discount('Promotion', new Money(600, 'USD')),
            Adjustment::shipping('Delivery', new Money(50, 'USD')),
        ],
    );

    expect($line->total()->amount)
        ->toBe(375)
        ->and($intent->total()->amount)
        ->toBe(50)
        ->and(Transaction::payment($intent)->fresh()->amount->eq(new Money(50, 'USD')))
        ->toBeTrue();
});

it('separates authorization, capture, void and partial refunds', function () {
    $authorization = Transaction::authorize(minos_transaction_intent())->handle(Continuation::success('auth-1'));
    expect($authorization->balanceImpact()->isZero())->toBeTrue();

    $capture = $authorization->capture('CAP-001', new Money(600, 'USD'));
    $capture->handle(Continuation::success('capture-1'));
    $void = $authorization->void('VOID-001', new Money(400, 'USD'));
    $void->handle(Continuation::success('void-1'));
    $refund = $capture->refund('REF-001', new Money(200, 'USD'), 'Partial return');
    $refund->handle(Continuation::success('refund-1'));

    expect($authorization->fresh()->type)
        ->toBe(TransactionType::AUTHORIZE)
        ->and($authorization->capturable()->isZero())
        ->toBeTrue()
        ->and($capture->balanceImpact()->amount)
        ->toBe(600)
        ->and($capture->refundable()->amount)
        ->toBe(400)
        ->and($void->balanceImpact()->isZero())
        ->toBeTrue()
        ->and($refund->balanceImpact()->amount)
        ->toBe(-200)
        ->and($refund->parent->is($capture))
        ->toBeTrue()
        ->and($refund->source_id)
        ->toBe($authorization->source_id)
        ->and($refund->payer_id)
        ->toBe($authorization->payer_id)
        ->and($refund->method_id)
        ->toBe($authorization->method_id);
});

it('reserves pending amounts and releases failed operations', function () {
    $authorization = Transaction::authorize(minos_transaction_intent())->handle(Continuation::success('auth-1'));
    $stale = $authorization->fresh();
    $capture = $authorization->capture('CAP-001', new Money(700, 'USD'));

    expect(fn () => $stale->void('VOID-001', new Money(400, 'USD')))->toThrow(InvalidArgumentException::class);
    expect($authorization->capturable()->amount)->toBe(300);

    $capture->handle(Continuation::failure());
    expect($authorization->capturable()->amount)->toBe(1000);
    $authorization->capture('CAP-002', new Money(1000, 'USD'));
    expect($authorization->capturable()->isZero())->toBeTrue();
});

it('reserves refunds across stale model instances', function () {
    $payment = Transaction::payment(minos_transaction_intent())->handle(Continuation::success());
    $stale = $payment->fresh();
    $refund = $payment->refund('REF-001', new Money(800, 'USD'), 'Return');

    expect(fn () => $stale->refund('REF-002', new Money(300, 'USD'), 'Return'))
        ->toThrow(InvalidArgumentException::class);
    $refund->handle(new Continuation(TransactionStatus::Cancel));
    expect($payment->refundable()->amount)->toBe(1000);
});

it('does not repeat terminal transitions or regress completed operations', function () {
    $transaction = Transaction::payment(minos_transaction_intent());
    $stale = $transaction->fresh();
    $updates = 0;
    Transaction::updated(function (Transaction $record) use (&$updates) {
        if ($record->wasChanged('status')) {
            $updates++;
        }
    });
    $transaction->handle(Continuation::pending('remote-1', ['redirect' => 'https://example.test/pay']));
    $transaction->handle(Continuation::success('remote-1', ['receipt' => 'https://example.test/receipt']));
    $processed = $transaction->processed_at;
    $stale->handle(Continuation::success('remote-1'));
    $stale->handle(Continuation::pending());
    $stale->handle(Continuation::failure());

    expect($updates)
        ->toBe(1)
        ->and($stale->status)
        ->toBe(TransactionStatus::Success)
        ->and($stale->processed_at->eq($processed))
        ->toBeTrue()
        ->and($stale->metadata)
        ->toBe([
            'redirect' => 'https://example.test/pay',
            'receipt' => 'https://example.test/receipt',
        ]);
});

it('rejects operations without a successful eligible parent', function () {
    $payment = Transaction::payment(minos_transaction_intent());
    expect(fn () => $payment->refund('REF-001', new Money(100, 'USD'), 'Return'))->toThrow(LogicException::class);
    $payment->handle(Continuation::success());
    expect(fn () => $payment->capture('CAP-001', new Money(100, 'USD')))->toThrow(LogicException::class);
    $authorization = Transaction::authorize(minos_transaction_intent())->handle(Continuation::success());
    expect(fn () => $authorization->refund('REF-002', new Money(100, 'USD'), 'Return'))->toThrow(LogicException::class);
});

it('rejects non-positive amounts and currency mismatches', function () {
    expect(fn () => Transaction::payment(minos_transaction_intent(amount: 0)))
        ->toThrow(InvalidArgumentException::class);
    $payment = Transaction::payment(minos_transaction_intent())->handle(Continuation::success());
    expect(fn () => $payment->refund('REF-001', new Money(-1, 'USD'), 'Return'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $payment->refund('REF-002', new Money(100, 'EUR'), 'Return'))
        ->toThrow(InvalidArgumentException::class);
});

it('executes bePaid operations using persisted transaction identifiers', function () {
    Http::fake(['gateway.bepaid.by/*' => Http::sequence()
        ->push(['transaction' => ['uid' => 'auth-1', 'status' => 'successful']])
        ->push(['transaction' => ['uid' => 'capture-1', 'status' => 'successful']])
        ->push(['transaction' => ['uid' => 'refund-1', 'status' => 'successful']])
        ->push(['transaction' => ['uid' => 'void-1', 'status' => 'successful']])]);
    $gateway = new BePaid;
    $method = $gateway->define(['shop_id' => 'shop', 'secret_key' => 'secret', 'test_mode' => true]);
    $method->save();
    $intent = minos_transaction_intent($method);
    $authorization = Transaction::authorize($intent);
    $authorization->handle($gateway->authorize($intent));
    $capture = $authorization->capture('CAP-001', new Money(700, 'USD'));
    $capture->handle($gateway->capture($capture));
    $refund = $capture->refund('REF-001', new Money(200, 'USD'), 'Returned item');
    $refund->handle($gateway->refund($refund));
    $void = $authorization->void('VOID-001', new Money(300, 'USD'));
    $void->handle($gateway->void($void));

    foreach ([
        [$capture, 'captures', 'auth-1'],
        [$refund,  'refunds',  'capture-1'],
        [$void,    'voids',    'auth-1'],
    ] as [$transaction, $endpoint, $parentId]) {
        Http::assertSent(
            fn ($request) => (
                $request->url() === 'https://gateway.bepaid.by/transactions/' . $endpoint
                && $request->hasHeader('RequestID', $transaction->id)
                && $request['request']['tracking_id'] === $transaction->id
                && $request['request']['parent_uid'] === $parentId
                && $request['request']['amount'] === $transaction->amount->amount
            ),
        );
    }
    Http::assertSent(fn ($request) => ($request['request']['reason'] ?? null) === 'Returned item');
    expect($authorization->balanceImpact()->amount)
        ->toBe(0)
        ->and($capture->balanceImpact()->amount)
        ->toBe(700)
        ->and($refund->balanceImpact()->amount)
        ->toBe(-200)
        ->and($void->balanceImpact()->amount)
        ->toBe(0);
});

it('preserves expiry for both bePaid payment and authorization', function (string $operation) {
    Date::setTestNow('2026-09-19 12:00:00 UTC');
    Http::fake(['gateway.bepaid.by/*' => Http::response(['transaction' => [
        'status' => 'incomplete',
        'uid' => 'remote-1',
    ]])]);
    $gateway = new BePaid;
    $method = $gateway->define(['shop_id' => 'shop', 'secret_key' => 'secret', 'timeout' => 600]);
    $method->save();

    try {
        $result = $gateway->{$operation}(minos_transaction_intent($method));
        expect($result->expiresAt->format(DATE_ATOM))->toBe('2026-09-19T12:10:00+00:00');
        Http::assertSent(fn ($request) => $request['request']['expired_at'] === '2026-09-19T12:10:00+00:00');
    } finally {
        Date::setTestNow();
    }
})->with(['intent', 'authorize']);

it('keeps a transaction pending when a transport request fails', function () {
    Http::fake(['gateway.bepaid.by/*' => Http::failedConnection()]);
    $gateway = new BePaid;
    $method = $gateway->define(['shop_id' => 'shop', 'secret_key' => 'secret']);
    $method->save();
    $intent = minos_transaction_intent($method);
    $transaction = Transaction::payment($intent);

    expect(fn () => $transaction->handle($gateway->intent($intent)))
        ->toThrow(\Illuminate\Http\Client\ConnectionException::class);
    expect($transaction->fresh()->status)->toBe(TransactionStatus::Pending);
});

it('uses the configured database connection for records and child operations', function () {
    config()->set('database.connections.minos', config('database.connections.sqlite'));
    config()->set('phpinnacle-minos.connection', 'minos');

    $this->artisan('migrate', [
        '--database' => 'minos',
        '--path' => realpath(__DIR__ . '/../../database/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();

    $payment = Transaction::payment(minos_transaction_intent())->handle(Continuation::success());
    $refund = $payment->refund('REF-001', new Money(100, 'USD'), 'Return');
    $refund->handle(Continuation::success());

    expect($payment->getConnection()->getName())
        ->toBe('minos')
        ->and(Schema::connection('minos')->hasTable('payment_transactions'))
        ->toBeTrue()
        ->and(Transaction::query()->count())
        ->toBe(2)
        ->and(\Illuminate\Support\Facades\DB::connection('sqlite')->table('payment_transactions')->count())
        ->toBe(0)
        ->and($payment->refundable()->amount)
        ->toBe(900);
});

it('protects parent transactions and payment methods from deletion', function () {
    $payment = Transaction::payment(minos_transaction_intent())->handle(Continuation::success());
    $payment->refund('REF-001', new Money(100, 'USD'), 'Return');

    expect($payment->delete(...))->toThrow(\Illuminate\Database\QueryException::class);
    expect(fn () => $payment->method->delete())->toThrow(\Illuminate\Database\QueryException::class);
    expect(Transaction::query()->count())->toBe(2)->and(PaymentMethod::query()->count())->toBe(1);
});

it('rolls back all payment tables from the consolidated migration', function () {
    $payment = Transaction::payment(minos_transaction_intent())->handle(Continuation::success());
    $payment->refund('REF-001', new Money(100, 'USD'), 'Return');

    (require __DIR__ . '/../../database/migrations/create_minos_tables.php')->down();

    expect(Schema::hasTable('payment_transactions'))
        ->toBeFalse()
        ->and(Schema::hasTable('payment_methods'))
        ->toBeFalse()
        ->and(Schema::hasTable('payment_plans'))
        ->toBeFalse()
        ->and(Schema::hasTable('payment_cards'))
        ->toBeFalse();
});

it('preserves operation statuses while child operations change payment balances', function () {
    $root = Transaction::authorize(minos_transaction_intent())->handle(Continuation::success('payment-1'));
    expect($root->status)->toBe(TransactionStatus::Success)->and($root->type)->toBe(TransactionType::AUTHORIZE);
    $processedAt = $root->processed_at;

    $capture = $root->capture('CAP-001', new Money(600, 'USD'))->handle(Continuation::success('capture-1'));
    expect($root->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($root->captured()->amount)
        ->toBe(600)
        ->and($root->capturable()->amount)
        ->toBe(400);

    $void = $root->void('VOID-001', new Money(400, 'USD'))->handle(Continuation::success());
    expect($void->status)
        ->toBe(TransactionStatus::Success)
        ->and($root->refresh()->status)
        ->toBe(TransactionStatus::Success);

    $capture->refund('REF-001', new Money(200, 'USD'), 'Return')->handle(Continuation::success());
    expect($root->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($root->refunded()->amount)
        ->toBe(200)
        ->and($root->received()->amount)
        ->toBe(400)
        ->and($root->external_id)
        ->toBe('payment-1')
        ->and($root->balanceImpact()->amount)
        ->toBe(0);

    $refund = $capture->refund('REF-002', new Money(400, 'USD'), 'Return')->handle(Continuation::success());
    expect($root->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($root->received()->amount)
        ->toBe(0)
        ->and($capture->refresh()->balanceImpact()->amount)
        ->toBe(600)
        ->and($capture->status)
        ->toBe(TransactionStatus::Success)
        ->and($refund->status)
        ->toBe(TransactionStatus::Success)
        ->and($refund->type)
        ->toBe(TransactionType::REFUND)
        ->and($root->processed_at->eq($processedAt))
        ->toBeTrue();
});

it('reconciles a reversed refund and recomputes the root without double counting', function () {
    $root = Transaction::payment(minos_transaction_intent())->handle(Continuation::success());
    $refund = $root->refund('REF-001', new Money(300, 'USD'), 'Return')->handle(Continuation::success());
    expect($root->refresh()->status)->toBe(TransactionStatus::Success);

    $refund->synchronize(Continuation::pending(), $root->refresh()->version);
    expect($root->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($root->received()->amount)
        ->toBe(1000)
        ->and($root->refundable()->amount)
        ->toBe(700)
        ->and($refund->processed_at)
        ->toBeNull()
        ->and($refund->status)
        ->toBe(TransactionStatus::Pending);

    $refund->synchronize(Continuation::failure(), $root->refresh()->version);
    expect($root->refresh()->refundable()->amount)->toBe(1000)->and($refund->status)->toBe(TransactionStatus::Failure);

    $refund->synchronize(Continuation::success(), $root->refresh()->version);
    $updates = 0;
    Transaction::updated(function () use (&$updates) {
        $updates++;
    });
    $refund->synchronize(Continuation::success(), $root->refresh()->version);

    expect($updates)
        ->toBe(0)
        ->and($root->refresh()->received()->amount)
        ->toBe(700)
        ->and($root->balanceImpact()->amount)
        ->toBe(1000)
        ->and($refund->balanceImpact()->amount)
        ->toBe(-300);
});

it('reconciles nested refund balances without changing successful parent statuses', function () {
    $root = Transaction::authorize(minos_transaction_intent())->handle(Continuation::success());
    $capture = $root->capture('CAP-001', new Money(1000, 'USD'))->handle(Continuation::success());
    $refund = $capture->refund('REF-001', new Money(1000, 'USD'), 'Return')->handle(Continuation::success());
    expect($root->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($capture->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($root->received()->amount)
        ->toBe(0)
        ->and($capture->received()->amount)
        ->toBe(0);

    $refund->synchronize(Continuation::failure(), $root->refresh()->version);

    expect($root->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($capture->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($root->received()->amount)
        ->toBe(1000)
        ->and($capture->refundable()->amount)
        ->toBe(1000)
        ->and($refund->root()->id)
        ->toBe($root->id);
});

it('keeps reservations separate from confirmed payment state', function () {
    $root = Transaction::authorize(minos_transaction_intent())->handle(Continuation::success());
    $capture = $root->capture('CAP-001', new Money(1000, 'USD'));
    expect($root->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($root->capturable()->amount)
        ->toBe(0)
        ->and($root->captured()->amount)
        ->toBe(0);
    $capture->handle(Continuation::failure());

    expect($root->refresh()->status)->toBe(TransactionStatus::Success)->and($root->capturable()->amount)->toBe(1000);
    $root->void('VOID-001', new Money(1000, 'USD'))->handle(Continuation::success());
    expect($root->refresh()->status)->toBe(TransactionStatus::Success)->and($root->received()->amount)->toBe(0);
});

it('synchronizes a retried payment without changing its identity', function () {
    $intent = minos_transaction_intent();
    $root = Transaction::payment($intent)->handle(Continuation::failure('payment-1'));
    $root->synchronize(Continuation::pending('payment-1'), $root->version);
    $root->handle(Continuation::success('payment-1'));

    expect($root->id)
        ->toBe($intent->id)
        ->and($root->status)
        ->toBe(TransactionStatus::Success)
        ->and($root->received()->amount)
        ->toBe(1000)
        ->and(Transaction::query()->count())
        ->toBe(1);
});

it('timestamps a corrected operation outcome without changing it on repeated synchronization', function () {
    Date::setTestNow('2026-09-19 12:00:00 UTC');

    try {
        $root = Transaction::payment(minos_transaction_intent())->handle(Continuation::success());
        $refund = $root->refund('REF-001', new Money(300, 'USD'), 'Return')->handle(Continuation::success());

        Date::setTestNow('2026-09-20 12:00:00 UTC');
        $refund->synchronize(Continuation::failure(), $root->refresh()->version);
        expect($refund->processed_at->format(DATE_ATOM))->toBe('2026-09-20T12:00:00+00:00');

        Date::setTestNow('2026-09-21 12:00:00 UTC');
        $refund->synchronize(Continuation::failure(), $root->refresh()->version);
        expect($refund->processed_at->format(DATE_ATOM))
            ->toBe('2026-09-20T12:00:00+00:00')
            ->and($root->refresh()->processed_at->format(DATE_ATOM))
            ->toBe('2026-09-19T12:00:00+00:00');
    } finally {
        Date::setTestNow();
    }
});

it('emits creation and status transition events without version or duplicate noise', function () {
    $created = [];
    $changes = [];
    Event::listen(TransactionCreated::class, function (TransactionCreated $event) use (&$created) {
        $created[] = $event->transaction->id;
    });
    Event::listen(TransactionStatusChanged::class, function (TransactionStatusChanged $event) use (&$changes) {
        $changes[] = [$event->transaction->id, $event->previousStatus, $event->status];
    });

    $payment = Transaction::payment(minos_transaction_intent());
    $payment->handle(Continuation::pending('remote-payment'));
    $payment->handle(Continuation::success('remote-payment'));
    $payment->handle(Continuation::success('remote-payment'));
    $refund = $payment->refund('REF-EVENT', new Money(200, 'USD'), 'Return');
    $refund->handle(Continuation::failure());
    $refund->synchronize(Continuation::pending(), $payment->refresh()->version);

    expect($created)
        ->toBe([$payment->id, $refund->id])
        ->and($changes)
        ->toBe([
            [$payment->id, TransactionStatus::Pending, TransactionStatus::Success],
            [$refund->id, TransactionStatus::Pending, TransactionStatus::Failure],
            [$refund->id, TransactionStatus::Failure, TransactionStatus::Pending],
        ]);
});

it('emits an update when a pending provider response changes checkout details', function () {
    $updated = [];
    $statuses = [];
    Event::listen(TransactionUpdated::class, function (TransactionUpdated $event) use (&$updated) {
        $updated[] = [$event->transaction->id, $event->transaction->metadata];
    });
    Event::listen(TransactionStatusChanged::class, function (TransactionStatusChanged $event) use (&$statuses) {
        $statuses[] = $event->status;
    });

    $payment = Transaction::payment(minos_transaction_intent());
    $pending = Continuation::pending('remote-payment', ['redirect' => 'https://checkout.example.test/1']);
    $payment->handle($pending);
    $payment->handle($pending);
    $redirect = Continuation::pending(metadata: ['redirect' => 'https://checkout.example.test/2']);
    $payment->handle($redirect);
    $payment->handle($redirect);

    expect($payment->status)
        ->toBe(TransactionStatus::Pending)
        ->and($updated)
        ->toBe([
            [$payment->id, ['redirect' => 'https://checkout.example.test/1']],
            [$payment->id, ['redirect' => 'https://checkout.example.test/2']],
        ])
        ->and($statuses)
        ->toBe([]);

    $payment->handle(Continuation::success('remote-payment'));
    $payment->refund('REF-EVENT', new Money(100, 'USD'), 'Return');

    expect($updated)->toHaveCount(3)->and($statuses)->toBe([TransactionStatus::Success]);
});

it('delivers transaction events after commit and discards rolled-back events', function () {
    $created = [];
    $changed = [];
    $updated = [];
    Event::listen(TransactionCreated::class, function (TransactionCreated $event) use (&$created) {
        $created[] = $event->transaction->id;
    });
    Event::listen(TransactionStatusChanged::class, function (TransactionStatusChanged $event) use (&$changed) {
        $changed[] = $event->transaction->id;
    });
    Event::listen(TransactionUpdated::class, function (TransactionUpdated $event) use (&$updated) {
        $updated[] = $event->transaction->id;
    });

    $method = new Cash()->define();
    $method->save();

    $payment = $method
        ->getConnection()
        ->transaction(function () use ($method, &$created, &$changed, &$updated) {
            $payment = Transaction::payment(minos_transaction_intent($method));
            $payment->handle(Continuation::success());

            expect($created)->toBe([])->and($changed)->toBe([])->and($updated)->toBe([]);

            return $payment;
        });

    expect($created)
        ->toBe([$payment->id])
        ->and($changed)
        ->toBe([$payment->id])
        ->and($updated)
        ->toBe([$payment->id]);

    expect(fn () => $method
        ->getConnection()
        ->transaction(function () use ($method) {
            Transaction::payment(minos_transaction_intent($method))->handle(Continuation::success());

            throw new RuntimeException('Rollback event test');
        }))->toThrow(RuntimeException::class, 'Rollback event test');

    expect($created)
        ->toBe([$payment->id])
        ->and($changed)
        ->toBe([$payment->id])
        ->and($updated)
        ->toBe([$payment->id]);
});

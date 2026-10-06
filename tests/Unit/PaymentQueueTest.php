<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPinnacle\Minos\Contracts\Instrument;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Exceptions\StaleTransaction;
use PHPinnacle\Minos\Instruments\Bypass;
use PHPinnacle\Minos\Instruments\CardToken;
use PHPinnacle\Minos\Instruments\EncryptedCard;
use PHPinnacle\Minos\Jobs\ProcessPayment;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\CreditCard;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\IntentLine;
use PHPinnacle\Minos\Models\Notification;
use PHPinnacle\Minos\Models\Payer;
use PHPinnacle\Minos\Models\Source;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Payments\Bank;
use PHPinnacle\Minos\Payments\BePaid;
use PHPinnacle\Minos\Payments\Cash;
use PHPinnacle\Minos\Services\PaymentManager;
use PHPinnacle\Money\Money;
use Tests\TestCase;

uses(TestCase::class);

function minos_queue_intent(?Instrument $instrument = null): Intent
{
    $method = new BePaid()->define([
        'shop_id' => 'shop',
        'secret_key' => 'secret',
        'test_mode' => true,
        'timeout' => 1800,
    ]);
    $method->save();
    $payer = new Payer((string) Str::uuid(), 'customer');

    if ($instrument === null) {
        $card = CreditCard::query()->create([
            'method_id' => $method->id,
            'customer_id' => $payer->id,
            'customer_type' => $payer->type,
            'token' => 'provider-card-token',
            'expires_at' => now()->addYear(),
        ]);
        $instrument = new CardToken($card->id);
    }

    return new Intent(
        id: (string) Str::uuid(),
        number: 'PAY-001',
        description: 'Order payment',
        method: $method,
        source: new Source('00000000-0000-0000-0000-000000000001', 'order'),
        payer: $payer,
        instrument: $instrument,
        lines: [new IntentLine('Item', 1, new Money(1000, 'USD'))],
    );
}

function minos_encrypted_queue_intent(bool $persist = false): Intent
{
    $card = new EncryptedCard(
        'encrypted-number',
        'encrypted-holder',
        'encrypted-month',
        'encrypted-year',
        'encrypted-cvc',
    );

    return minos_queue_intent($persist ? $card->persisted() : $card);
}

function minos_queued_payment(): ProcessPayment
{
    $payload = json_decode(DB::connection('minos')->table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);

    return unserialize(Crypt::decrypt($payload['data']['command']));
}

beforeEach(function () {
    config()->set('database.connections.minos', config('database.connections.sqlite'));
    config()->set('phpinnacle-minos.connection', 'minos');
    DB::setDefaultConnection('minos');
    (require __DIR__ . '/../../database/migrations/create_minos_tables.php')->up();
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Schema::create('failed_jobs', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
    DB::setDefaultConnection('sqlite');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'minos',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 150,
        'after_commit' => true,
    ]);
    config()->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'minos', 'table' => 'failed_jobs']);
    config()->set('cache.default', 'array');
    Http::preventStrayRequests();
});

it('discards a payment and its scheduled execution when the application rolls back', function () {
    $intent = minos_queue_intent();

    expect(fn () => DB::connection('minos')->transaction(function () use ($intent) {
        app(PaymentManager::class)->payment($intent);
        throw new RuntimeException('Application rollback');
    }))
        ->toThrow(RuntimeException::class, 'Application rollback');

    expect(Transaction::query()->count())->toBe(0)->and(DB::connection('minos')->table('jobs')->count())->toBe(0);
    Http::assertNothingSent();
});

it('executes an encrypted Laravel job after commit without a Filament panel', function () {
    $transaction = app(PaymentManager::class)->payment(minos_queue_intent());
    $job = minos_queued_payment();
    expect(DB::connection('minos')->table('jobs')->value('payload'))
        ->not
        ->toContain('provider-card-token', 'secret');
    Http::assertNothingSent();

    Http::fake(function (Request $request) use ($transaction) {
        expect(DB::connection('minos')->transactionLevel())
            ->toBe(0)
            ->and($request->header('RequestID'))
            ->toBe([$transaction->id])
            ->and($request['request']['credit_card']['token'])
            ->toBe('provider-card-token');

        return Http::response(['transaction' => ['uid' => 'remote-payment', 'status' => 'successful']]);
    });
    Queue::connection('database')->pop('payments')->fire();
    expect($transaction->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and(DB::connection('minos')->table('jobs')->count())
        ->toBe(0);

    $job->handle(app(PaymentManager::class));
    Http::assertSentCount(1);
});

it('executes a queued BePaid payment with a new encrypted card', function () {
    $transaction = app(PaymentManager::class)->payment(minos_encrypted_queue_intent());
    $jobPayload = DB::connection('minos')->table('jobs')->value('payload');

    expect($jobPayload)->not->toContain('encrypted-number', 'encrypted-cvc');

    Http::fake(function (Request $request) use ($transaction) {
        expect($request->header('RequestID'))
            ->toBe([$transaction->id])
            ->and($request['request']['encrypted_credit_card'])
            ->toBe([
                'number' => 'encrypted-number',
                'holder' => 'encrypted-holder',
                'exp_month' => 'encrypted-month',
                'exp_year' => 'encrypted-year',
                'verification_value' => 'encrypted-cvc',
            ]);

        return Http::response([
            'transaction' => [
                'uid' => 'remote-payment',
                'status' => 'successful',
                'credit_card' => [
                    'token' => 'unrequested-card-token',
                    'exp_month' => '02',
                    'exp_year' => '2028',
                ],
            ],
        ]);
    });

    Queue::connection('database')->pop('payments')->fire();

    expect($transaction->refresh()->status)->toBe(TransactionStatus::Success);
    expect($transaction->metadata)->not->toHaveKey('encrypted_credit_card');
    expect(json_encode($transaction->getAttributes(), JSON_THROW_ON_ERROR))
        ->not
        ->toContain('encrypted-number', 'encrypted-cvc');
    expect(CreditCard::query()->count())->toBe(0);
});

it('saves a new card after a queued BePaid payment succeeds', function (bool $reconcile) {
    $transaction = app(PaymentManager::class)->payment(minos_encrypted_queue_intent(persist: true));
    Http::fake(function (Request $request) use ($reconcile) {
        if ($reconcile && $request->method() === 'POST') {
            return Http::response(['transaction' => ['uid' => 'remote-payment', 'status' => 'pending']]);
        }

        return Http::response([
            'transaction' => [
                'uid' => 'remote-payment',
                'status' => 'successful',
                'credit_card' => [
                    'token' => 'new-card-token',
                    'exp_month' => '02',
                    'exp_year' => '2028',
                ],
            ],
        ]);
    });

    Queue::connection('database')->pop('payments')->fire();

    if ($reconcile) {
        expect($transaction->refresh()->status)->toBe(TransactionStatus::Pending);
        app(PaymentManager::class)->synchronize($transaction);
        Queue::connection('database')->pop('payments')->fire();
    }

    $card = CreditCard::query()->where('token', 'new-card-token')->sole();

    expect($transaction->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($transaction->metadata['payment_card_id'])
        ->toBe($card->id)
        ->and($card->customer_id)
        ->toBe($transaction->payer_id);
})->with(['immediate response' => false, 'reconciliation' => true]);

it('rejects a queue on a different database connection without retaining the payment', function () {
    config()->set('queue.connections.database.connection', 'sqlite');

    expect(fn () => app(PaymentManager::class)->payment(minos_queue_intent()))
        ->toThrow(LogicException::class, 'same database connection');
    expect(Transaction::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects a non-database queue without executing the payment', function () {
    config()->set('phpinnacle-minos.queue.connection', 'sync');

    expect(fn () => app(PaymentManager::class)->payment(minos_queue_intent()))
        ->toThrow(LogicException::class, 'database queue');
    expect(Transaction::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('rolls back the payment if inserting its job fails', function () {
    $intent = minos_queue_intent();
    Schema::connection('minos')->drop('jobs');

    expect(fn () => app(PaymentManager::class)->payment($intent))->toThrow(Illuminate\Database\QueryException::class);
    expect(Transaction::query()->count())->toBe(0);
});

it('rolls back a reserved refund when enqueueing fails', function () {
    $payment = Transaction::payment(minos_queue_intent())->handle(Continuation::success('remote-payment'));
    Schema::connection('minos')->drop('jobs');

    expect(fn () => app(PaymentManager::class)->refund($payment, 'REF-001', new Money(700, 'USD'), 'Return'))
        ->toThrow(Illuminate\Database\QueryException::class);
    expect($payment->children()->count())->toBe(0)->and($payment->refundable()->amount)->toBe(1000);
});

it('executes authorization and child operations through the same database queue', function () {
    Http::fake(['gateway.bepaid.by/*' => Http::sequence()
        ->push(['transaction' => ['uid' => 'remote-auth', 'status' => 'successful']])
        ->push(['transaction' => ['uid' => 'remote-capture', 'status' => 'successful']])
        ->push(['transaction' => ['uid' => 'remote-void', 'status' => 'successful']])
        ->push(['transaction' => ['uid' => 'remote-refund', 'status' => 'successful']])]);
    $payments = app(PaymentManager::class);
    $root = $payments->authorize(minos_queue_intent());
    Queue::connection('database')->pop('payments')->fire();
    $capture = $payments->capture($root->refresh(), 'CAP-001', new Money(600, 'USD'));
    Queue::connection('database')->pop('payments')->fire();
    $payments->void($root->refresh(), 'VOID-001', new Money(400, 'USD'));
    Queue::connection('database')->pop('payments')->fire();
    $payments->refund($capture->refresh(), 'REF-001', new Money(200, 'USD'), 'Return');
    Queue::connection('database')->pop('payments')->fire();

    expect($root->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($root->received()->amount)
        ->toBe(400)
        ->and($root->capturable()->amount)
        ->toBe(0)
        ->and(DB::connection('minos')->table('jobs')->count())
        ->toBe(0);
    Http::assertSent(
        fn (Request $request) => (
            $request->url() === 'https://gateway.bepaid.by/transactions/refunds'
            && $request['request']['parent_uid'] === 'remote-capture'
            && $request['request']['amount'] === 200
        ),
    );
});

it('replays the exact request after a response is lost before local commit', function () {
    $transaction = app(PaymentManager::class)->payment(minos_queue_intent());
    $job = minos_queued_payment();
    $payloads = [];
    Http::fake(function (Request $request) use (&$payloads) {
        $payloads[] = [$request->header('RequestID'), $request->data()];

        return Http::response(['transaction' => ['uid' => 'remote-payment', 'status' => 'successful']]);
    });
    $fail = true;
    Transaction::updated(function (Transaction $record) use (&$fail) {
        if ($record->status === TransactionStatus::Success && $fail) {
            $fail = false;
            throw new RuntimeException('Local commit failed');
        }
    });
    expect(fn () => $job->handle(app(PaymentManager::class)))->toThrow(RuntimeException::class, 'Local commit failed');
    expect($transaction->refresh()->status)->toBe(TransactionStatus::Pending);

    $this->travel(5)->minutes();
    $job->handle(app(PaymentManager::class));
    expect($payloads[1])->toBe($payloads[0])->and($transaction->refresh()->received()->amount)->toBe(1000);
});

it('keeps unknown remote outcomes pending and retains the reservation', function () {
    $payment = Transaction::payment(minos_queue_intent())->handle(Continuation::success('remote-payment'));
    $refund = app(PaymentManager::class)->refund($payment, 'REF-001', new Money(700, 'USD'), 'Return');
    Http::fake(fn () => throw new ConnectionException('Response lost'));

    expect(fn () => minos_queued_payment()->handle(app(PaymentManager::class)))->toThrow(ConnectionException::class);
    expect($refund->refresh()->status)
        ->toBe(TransactionStatus::Pending)
        ->and($payment->refundable()->amount)
        ->toBe(300);
});

it('does not turn transport or malformed responses into a declined payment', function (
    mixed $response,
    int $status,
    string $exception,
) {
    $transaction = app(PaymentManager::class)->payment(minos_queue_intent());
    Http::fake(['*' => Http::response($response, $status)]);

    expect(fn () => minos_queued_payment()->handle(app(PaymentManager::class)))->toThrow($exception);
    expect($transaction->refresh()->status)->toBe(TransactionStatus::Pending);
})->with([
    'server error' => [[], 503, RequestException::class],
    'bad credentials' => [[], 401, RequestException::class],
    'missing transaction' => [[], 200, UnexpectedValueException::class],
    'invalid JSON' => ['invalid JSON', 200, UnexpectedValueException::class],
]);

it('stops replaying after the provider idempotency window expires', function () {
    $transaction = app(PaymentManager::class)->payment(minos_queue_intent());
    $this->travel(24)->hours();

    expect(fn () => minos_queued_payment()->handle(app(PaymentManager::class)))
        ->toThrow(RuntimeException::class, 'replay window expired');
    Http::assertNothingSent();
    expect($transaction->refresh()->status)->toBe(TransactionStatus::Pending);
});

it('uses Laravel failed jobs and queue retry without releasing the payment reservation', function () {
    $transaction = app(PaymentManager::class)->payment(minos_queue_intent());
    DB::connection('minos')->table('jobs')->update(['attempts' => 4]);
    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection('Response lost')
        ->push(['transaction' => ['uid' => 'remote-payment', 'status' => 'successful']])]);
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'payments', '--once' => true])->run();

    expect(DB::connection('minos')->table('jobs')->count())
        ->toBe(0)
        ->and(DB::connection('minos')->table('failed_jobs')->count())
        ->toBe(1)
        ->and($transaction->refresh()->status)
        ->toBe(TransactionStatus::Pending);
    $uuid = DB::connection('minos')->table('failed_jobs')->value('uuid');
    $this->artisan('queue:retry', ['id' => [$uuid]])->assertSuccessful();
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'payments', '--once' => true])->run();

    expect($transaction->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and(DB::connection('minos')->table('failed_jobs')->count())
        ->toBe(0)
        ->and(DB::connection('minos')->table('jobs')->count())
        ->toBe(0);
});

it('defers another operation on the same payment until the running operation finishes', function () {
    $root = Transaction::authorize(minos_queue_intent())->handle(Continuation::success('remote-auth'));
    $capture = app(PaymentManager::class)->capture($root, 'CAP-001', new Money(600, 'USD'));
    $void = app(PaymentManager::class)->void($root, 'VOID-001', new Money(400, 'USD'));
    $requests = [];
    Http::fake(function (Request $request) use ($void, &$requests) {
        $requests[] = $request->url();

        if ($request->url() === 'https://gateway.bepaid.by/transactions/captures') {
            Queue::connection('database')->pop('payments')->fire();
            expect($requests)->toBe(['https://gateway.bepaid.by/transactions/captures']);
            expect($void->refresh()->status)->toBe(TransactionStatus::Pending);
        }

        return Http::response(['transaction' => ['uid' => 'remote-operation', 'status' => 'successful']]);
    });

    Queue::connection('database')->pop('payments')->fire();

    expect($capture->refresh()->status)->toBe(TransactionStatus::Success);
    expect($void->refresh()->status)->toBe(TransactionStatus::Pending);
    expect($root->received()->amount)->toBe(600);

    $this->travel(11)->seconds();
    Queue::connection('database')->pop('payments')->fire();

    expect($void->refresh()->status)->toBe(TransactionStatus::Success);
    expect($root->capturable()->amount)->toBe(0)->and($root->received()->amount)->toBe(600);
    Http::assertSentCount(2);
});

it('rejects a stale synchronization response and fetches fresh state on retry', function () {
    $root = Transaction::payment(minos_queue_intent())->handle(Continuation::success('remote-payment'));
    $refund = $root->refund('REF-001', new Money(300, 'USD'), 'Return')->handle(Continuation::success('remote-refund'));
    app(PaymentManager::class)->synchronize($refund);
    $first = true;
    Http::fake(function (Request $request) use ($refund, &$first) {
        expect($request->method())
            ->toBe('GET')
            ->and($request->url())
            ->toBe('https://gateway.bepaid.by/transactions/remote-refund');

        if ($first) {
            $first = false;
            $refund->synchronize(Continuation::failure(), $refund->root()->refresh()->version);

            return Http::response(['transaction' => ['uid' => 'remote-refund', 'status' => 'pending']]);
        }

        return Http::response(['transaction' => ['uid' => 'remote-refund', 'status' => 'failed']]);
    });
    $job = minos_queued_payment();
    expect(fn () => $job->handle(app(PaymentManager::class)))->toThrow(StaleTransaction::class);
    expect($refund->refresh()->status)->toBe(TransactionStatus::Failure);
    $job->handle(app(PaymentManager::class));
    expect($root->refresh()->received()->amount)->toBe(1000);
});

it('rejects unsupported instruments and saved-card verification data before persisting an operation', function (bool $withVerification) {
    $original = minos_queue_intent();
    $intent = new Intent(
        $original->id,
        $original->number,
        $original->description,
        $original->method,
        $original->source,
        $original->payer,
        $withVerification ? new CardToken($original->instrument->id, 'cvc') : new Bypass,
        $original->lines,
    );

    expect(fn () => app(PaymentManager::class)->payment($intent))->toThrow(InvalidArgumentException::class);
    expect(Transaction::query()->count())->toBe(0)->and(DB::connection('minos')->table('jobs')->count())->toBe(0);
})->with([true, false]);

it('rejects a saved card belonging to another payer', function () {
    $intent = minos_queue_intent();
    CreditCard::query()
        ->whereKey($intent->instrument->id)
        ->update(['customer_id' => (string) Str::uuid()]);

    expect(fn () => app(PaymentManager::class)->payment($intent))->toThrow(ModelNotFoundException::class);
    expect(Transaction::query()->count())->toBe(0)->and(DB::connection('minos')->table('jobs')->count())->toBe(0);
});

it('keeps manual payments pending', function () {
    $original = minos_queue_intent();

    foreach ([new Cash, new Bank] as $gateway) {
        $method = $gateway->define(['name' => 'Bank transfer', 'account' => 'account']);
        $method->save();
        $intent = new Intent(
            (string) Str::uuid(),
            'PAY-001',
            'Payment',
            $method,
            $original->source,
            $original->payer,
            null,
            $original->lines,
        );

        expect($gateway->intent($intent)->status)
            ->toBe(TransactionStatus::Pending)
            ->and(app(PaymentManager::class)->payment($intent)->status)
            ->toBe(TransactionStatus::Pending);

        expect(fn () => $gateway->handle(new Notification('event', 'order', $method, $original->payer, [])))
            ->toThrow(LogicException::class);
    }

    expect(Transaction::query()->count())->toBe(2)->and(DB::connection('minos')->table('jobs')->count())->toBe(0);
    Http::assertNothingSent();
});

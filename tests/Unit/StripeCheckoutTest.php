<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Instruments\CardToken;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\IntentLine;
use PHPinnacle\Minos\Models\Payer;
use PHPinnacle\Minos\Models\Source;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Payments\Stripe as StripeGateway;
use PHPinnacle\Minos\Services\PaymentManager;
use PHPinnacle\Money\Money;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

uses(TestCase::class);

class MinosStripeHttpClient implements ClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $responses = [];

    /** @var list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>}> */
    public array $requests = [];

    // @mago-expect lint:excessive-parameter-list
    public function request(
        mixed $method,
        mixed $absUrl,
        mixed $headers,
        mixed $params,
        mixed $hasFile,
        mixed $apiMode = 'v1',
        mixed $maxNetworkRetries = null,
    ): array {
        $this->requests[] = [
            'method' => $method,
            'url' => $absUrl,
            'headers' => $headers,
            'params' => $params,
        ];

        return [json_encode(array_shift($this->responses), JSON_THROW_ON_ERROR), 200, []];
    }
}

function minos_stripe_intent(
    ?CardToken $instrument = null,
    bool $recurring = false,
    ?string $returnUrl = 'https://merchant.example.test/paid',
): Intent {
    $method = new StripeGateway()->define(['api_key' => 'sk_test_example', 'public_key' => 'pk_test_example']);
    $method->save();

    return new Intent(
        id: (string) Str::uuid(),
        number: 'PAY-STRIPE',
        description: 'Stripe order',
        method: $method,
        source: new Source('00000000-0000-0000-0000-000000000001', 'order'),
        payer: new Payer('00000000-0000-0000-0000-000000000002', 'customer', email: 'payer@example.test'),
        instrument: $instrument,
        lines: [new IntentLine('Item', 1, new Money(1000, 'USD'))],
        returnUrl: $returnUrl,
        cancelUrl: 'https://merchant.example.test/cancel',
        recurring: $recurring,
    );
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
    DB::setDefaultConnection('sqlite');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'minos',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 150,
        'after_commit' => true,
    ]);
    config()->set('cache.default', 'array');

    $this->stripeHttp = new MinosStripeHttpClient;
    ApiRequestor::setHttpClient($this->stripeHttp);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

it('creates a pending Checkout Session, reconciles payment, and refunds through the queue', function () {
    $this->stripeHttp->responses = [
        [
            'object' => 'checkout.session',
            'id' => 'cs_test_payment',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'url' => 'https://checkout.stripe.test/session',
            'expires_at' => now()->addHours(24)->timestamp,
            'payment_intent' => null,
        ],
        [
            'object' => 'checkout.session',
            'id' => 'cs_test_payment',
            'status' => 'complete',
            'payment_status' => 'paid',
            'url' => null,
            'expires_at' => now()->addHours(24)->timestamp,
            'payment_intent' => 'pi_payment',
        ],
        [
            'object' => 'refund',
            'id' => 're_refund',
            'status' => 'pending',
        ],
        [
            'object' => 'refund',
            'id' => 're_refund',
            'status' => 'succeeded',
        ],
    ];
    $payments = app(PaymentManager::class);
    $intent = minos_stripe_intent();
    $payment = $payments->payment($intent);

    expect($payment->status)->toBe(TransactionStatus::Pending);
    expect($this->stripeHttp->requests)->toBe([]);
    Queue::connection('database')->pop('payments')->fire();

    expect($payment->refresh()->metadata['redirect'])
        ->toBe('https://checkout.stripe.test/session')
        ->and($payment->status)
        ->toBe(TransactionStatus::Pending)
        ->and($payment->external_id)
        ->toBe('cs_test_payment');
    expect($this->stripeHttp->requests[0]['params']['line_items'][0]['price_data']['unit_amount'])
        ->toBe(1000)
        ->and($this->stripeHttp->requests[0]['params']['client_reference_id'])
        ->toBe($intent->id)
        ->and($this->stripeHttp->requests[0]['headers'])
        ->toContain('Idempotency-Key: ' . $payment->id);

    $payments->synchronize($payment);
    Queue::connection('database')->pop('payments')->fire();
    expect($payment->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($payment->metadata['payment_intent'])
        ->toBe('pi_payment');

    $refund = $payments->refund($payment, 'REF-STRIPE', new Money(300, 'USD'), 'Returned item');
    Queue::connection('database')->pop('payments')->fire();
    expect($refund->refresh()->status)
        ->toBe(TransactionStatus::Pending)
        ->and($payment->refundable()->amount)
        ->toBe(700)
        ->and($this->stripeHttp->requests[2]['params']['payment_intent'])
        ->toBe('pi_payment')
        ->and($this->stripeHttp->requests[2]['headers'])
        ->toContain('Idempotency-Key: ' . $refund->id);

    $payments->synchronize($refund);
    Queue::connection('database')->pop('payments')->fire();
    expect($refund->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($payment->refresh()->received()->amount)
        ->toBe(700);
});

it('authorizes with a direct PaymentIntent and captures the full hold later', function () {
    $this->stripeHttp->responses = [
        [
            'object' => 'payment_intent',
            'id' => 'pi_authorize',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_authorize_secret_example',
        ],
        [
            'object' => 'payment_intent',
            'id' => 'pi_authorize',
            'status' => 'requires_capture',
        ],
        [
            'object' => 'payment_intent',
            'id' => 'pi_authorize',
            'status' => 'succeeded',
        ],
        [
            'object' => 'refund',
            'id' => 're_captured',
            'status' => 'succeeded',
        ],
    ];
    $payments = app(PaymentManager::class);
    $authorization = $payments->authorize(minos_stripe_intent());
    Queue::connection('database')->pop('payments')->fire();

    expect($authorization->refresh()->status)
        ->toBe(TransactionStatus::Pending)
        ->and($authorization->metadata['client_secret'])
        ->toBe('pi_authorize_secret_example')
        ->and($this->stripeHttp->requests[0]['params']['capture_method'])
        ->toBe('manual')
        ->and($this->stripeHttp->requests[0]['params']['payment_method_types'])
        ->toBe(['card'])
        ->and($this->stripeHttp->requests[0]['url'])
        ->toContain('/payment_intents')
        ->and($this->stripeHttp->requests[0]['headers'])
        ->toContain('Idempotency-Key: ' . $authorization->id);

    $payments->synchronize($authorization);
    Queue::connection('database')->pop('payments')->fire();

    expect($authorization->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($authorization->external_id)
        ->toBe('pi_authorize')
        ->and($authorization->received()->amount)
        ->toBe(0)
        ->and($authorization->capturable()->amount)
        ->toBe(1000)
        ->and($this->stripeHttp->requests[1]['url'])
        ->toContain('/payment_intents/pi_authorize');

    $capture = $payments->capture($authorization, 'CAP-STRIPE', new Money(1000, 'USD'));
    Queue::connection('database')->pop('payments')->fire();

    expect($capture->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($capture->external_id)
        ->toBe('pi_authorize')
        ->and($authorization->refresh()->capturable()->amount)
        ->toBe(0)
        ->and($authorization->received()->amount)
        ->toBe(1000)
        ->and($this->stripeHttp->requests[2]['url'])
        ->toContain('/payment_intents/pi_authorize/capture')
        ->and($this->stripeHttp->requests[2]['params']['amount_to_capture'])
        ->toBe(1000)
        ->and($this->stripeHttp->requests[2]['headers'])
        ->toContain('Idempotency-Key: ' . $capture->id);

    $refund = $payments->refund($capture, 'REF-CAPTURE', new Money(300, 'USD'), 'Returned item');
    Queue::connection('database')->pop('payments')->fire();

    expect($refund->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($authorization->refresh()->received()->amount)
        ->toBe(700)
        ->and($this->stripeHttp->requests[3]['params']['payment_intent'])
        ->toBe('pi_authorize');
});

it('voids a complete Stripe authorization without collecting funds', function () {
    $this->stripeHttp->responses = [
        [
            'object' => 'payment_intent',
            'id' => 'pi_authorize',
            'status' => 'canceled',
        ],
        [
            'object' => 'payment_intent',
            'id' => 'pi_authorize',
            'status' => 'canceled',
        ],
    ];
    $authorization = Transaction::authorize(minos_stripe_intent())
        ->handle(Continuation::success('pi_authorize'));
    $void = app(PaymentManager::class)->void($authorization, 'VOID-STRIPE', new Money(1000, 'USD'));
    Queue::connection('database')->pop('payments')->fire();

    expect($void->refresh()->status)
        ->toBe(TransactionStatus::Success)
        ->and($authorization->refresh()->capturable()->amount)
        ->toBe(0)
        ->and($authorization->received()->amount)
        ->toBe(0)
        ->and($this->stripeHttp->requests[0]['url'])
        ->toContain('/payment_intents/pi_authorize/cancel')
        ->and($this->stripeHttp->requests[0]['headers'])
        ->toContain('Idempotency-Key: ' . $void->id);

    app(PaymentManager::class)->synchronize($authorization);
    Queue::connection('database')->pop('payments')->fire();
    expect($authorization->refresh()->status)->toBe(TransactionStatus::Success);
});

it('does not confirm a direct authorization canceled before reconciliation', function () {
    $this->stripeHttp->responses = [
        [
            'object' => 'payment_intent',
            'id' => 'pi_authorize',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_authorize_secret_example',
        ],
        [
            'object' => 'payment_intent',
            'id' => 'pi_authorize',
            'status' => 'canceled',
        ],
    ];
    $payments = app(PaymentManager::class);
    $authorization = $payments->authorize(minos_stripe_intent());
    Queue::connection('database')->pop('payments')->fire();
    $payments->synchronize($authorization);
    Queue::connection('database')->pop('payments')->fire();

    expect($authorization->refresh()->status)->toBe(TransactionStatus::Failure);
});

it('rejects partial Stripe capture and void before creating a queued operation', function (string $operation) {
    $authorization = Transaction::authorize(minos_stripe_intent())
        ->handle(Continuation::success('pi_authorize'));

    expect(fn () => app(PaymentManager::class)->$operation($authorization, 'PARTIAL', new Money(300, 'USD')))
        ->toThrow(InvalidArgumentException::class, 'full capture or void');
    expect(Transaction::query()->count())
        ->toBe(1)
        ->and(DB::connection('minos')->table('jobs')->count())
        ->toBe(0)
        ->and($this->stripeHttp->requests)
        ->toBe([]);
})->with(['capture', 'void']);

it('rejects unsupported Stripe Checkout inputs before creating a transaction', function (
    ?CardToken $instrument,
    bool $recurring,
) {
    $intent = minos_stripe_intent($instrument, $recurring);

    expect(fn () => app(PaymentManager::class)->payment($intent))->toThrow(InvalidArgumentException::class);
    expect(Transaction::query()->count())->toBe(0);
})->with([
    'saved card' => [new CardToken('local-card-id'), false],
    'subscription' => [null, true],
]);

it('requires a return URL before creating a Checkout payment', function () {
    expect(fn () => app(PaymentManager::class)->payment(minos_stripe_intent(returnUrl: null)))
        ->toThrow(InvalidArgumentException::class, 'return URL');
    expect(Transaction::query()->count())->toBe(0);
});

it('fails an expired Checkout Session without treating it as paid', function () {
    $this->stripeHttp->responses = [
        [
            'object' => 'checkout.session',
            'id' => 'cs_test_expired',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'url' => 'https://checkout.stripe.test/session',
            'expires_at' => now()->addHours(24)->timestamp,
            'payment_intent' => null,
        ],
        [
            'object' => 'checkout.session',
            'id' => 'cs_test_expired',
            'status' => 'expired',
            'payment_status' => 'unpaid',
            'url' => null,
            'expires_at' => now()->timestamp,
            'payment_intent' => null,
        ],
    ];
    $payments = app(PaymentManager::class);
    $payment = $payments->payment(minos_stripe_intent());
    Queue::connection('database')->pop('payments')->fire();
    $payments->synchronize($payment);
    Queue::connection('database')->pop('payments')->fire();

    expect($payment->refresh()->status)
        ->toBe(TransactionStatus::Failure)
        ->and($payment->received()->amount)
        ->toBe(0);
});

it('fails a completed Checkout payment when its delayed PaymentIntent fails', function () {
    $this->stripeHttp->responses = [
        [
            'object' => 'checkout.session',
            'id' => 'cs_test_delayed',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'url' => 'https://checkout.stripe.test/session',
            'payment_intent' => null,
        ],
        [
            'object' => 'checkout.session',
            'id' => 'cs_test_delayed',
            'status' => 'complete',
            'payment_status' => 'unpaid',
            'url' => null,
            'payment_intent' => 'pi_failed',
        ],
        [
            'object' => 'payment_intent',
            'id' => 'pi_failed',
            'status' => 'requires_payment_method',
        ],
    ];
    $payments = app(PaymentManager::class);
    $payment = $payments->payment(minos_stripe_intent());
    Queue::connection('database')->pop('payments')->fire();
    $payments->synchronize($payment);
    Queue::connection('database')->pop('payments')->fire();

    expect($payment->refresh()->status)
        ->toBe(TransactionStatus::Failure)
        ->and($payment->received()->amount)
        ->toBe(0);
});

it('releases a refund reservation when Stripe reports failure', function () {
    $this->stripeHttp->responses = [[
        'object' => 'refund',
        'id' => 're_failed',
        'status' => 'failed',
    ]];
    $payment = Transaction::payment(minos_stripe_intent())
        ->handle(Continuation::success('cs_paid', ['payment_intent' => 'pi_paid']));
    $refund = app(PaymentManager::class)->refund($payment, 'REF-FAILED', new Money(300, 'USD'), 'Returned item');
    Queue::connection('database')->pop('payments')->fire();

    expect($refund->refresh()->status)
        ->toBe(TransactionStatus::Failure)
        ->and($payment->refresh()->refundable()->amount)
        ->toBe(1000)
        ->and($payment->received()->amount)
        ->toBe(1000);
});

it('rejects a paid Checkout response without a PaymentIntent', function () {
    $this->stripeHttp->responses = [[
        'object' => 'checkout.session',
        'id' => 'cs_test_incomplete',
        'status' => 'complete',
        'payment_status' => 'paid',
        'url' => null,
        'expires_at' => now()->addHours(24)->timestamp,
        'payment_intent' => null,
    ]];
    $payment = app(PaymentManager::class)->payment(minos_stripe_intent());
    $payload = json_decode(DB::connection('minos')->table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
    $job = unserialize(Crypt::decrypt($payload['data']['command']));

    expect(fn () => $job->handle(app(PaymentManager::class)))
        ->toThrow(UnexpectedValueException::class, 'PaymentIntent');
    expect($payment->refresh()->status)->toBe(TransactionStatus::Pending);
});

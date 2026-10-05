<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPinnacle\Minos\Contracts\PaymentProvider;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Exceptions\PaymentDenied;
use PHPinnacle\Minos\Instruments\EncryptedCard;
use PHPinnacle\Minos\Models\CreditCard;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\IntentLine;
use PHPinnacle\Minos\Models\Notification;
use PHPinnacle\Minos\Models\Payer;
use PHPinnacle\Minos\Models\Source;
use PHPinnacle\Minos\Payments\BePaid;
use PHPinnacle\Minos\Payments\Erip;
use PHPinnacle\Minos\Payments\WebPay;
use PHPinnacle\Minos\Services\WebPay\RequestSigner;
use PHPinnacle\Money\Money;
use Tests\TestCase;

uses(TestCase::class);

function minos_provider_intent(PaymentProvider $provider): Intent
{
    $method = $provider->define([
        'shop_id' => 'shop',
        'secret_key' => 'secret',
        'service' => '12345',
        'timeout' => 600,
    ]);
    $method->save();

    return new Intent(
        (string) Str::uuid(),
        'PAY-1',
        'Item',
        $method,
        new Source('1', 'order'),
        new Payer((string) Str::uuid(), 'customer'),
        new EncryptedCard('number', 'holder', 'month', 'year', 'cvc', persist: true),
        [new IntentLine('Item', 1, new Money(1000, 'USD'))],
        notifyUrl: 'https://example.test/webhook',
    );
}

function minos_card_response(): array
{
    return [
        'transaction' => [
            'uid' => 'remote-payment',
            'status' => 'successful',
            'credit_card' => [
                'token' => 'card-token',
                'brand' => 'visa',
                'product' => null,
                'issuer_country' => 'US',
                'exp_month' => '02',
                'exp_year' => '2028',
                'bin_8' => '12345678',
                'last_4' => '1234',
            ],
        ],
    ];
}

beforeEach(function () {
    (require __DIR__ . '/../../database/migrations/create_minos_tables.php')->up();
    Http::preventStrayRequests();
});

it('maps card and ERIP responses and notifications to the same statuses', function (
    string $providerClass,
    string $status,
    TransactionStatus $expectedStatus,
) {
    $provider = new $providerClass;
    $intent = minos_provider_intent($provider);
    $payload = ['transaction' => ['uid' => 'remote-payment', 'status' => $status]];
    Http::fake(['*' => Http::response($payload)]);

    $result = $provider->intent($intent);
    $callback = $provider->handle(new Notification('1', '1', $intent->method, $intent->payer, $payload));
    expect($result->status)
        ->toBe($expectedStatus)
        ->and($callback->status)
        ->toBe($expectedStatus)
        ->and($result->externalId)
        ->toBe('remote-payment')
        ->and($callback->externalId)
        ->toBe('remote-payment')
        ->and($result->response)
        ->toBe($payload)
        ->and($callback->response)
        ->toBe($payload);
})->with([BePaid::class, Erip::class])->with([
    ['successful', TransactionStatus::Success],
    ['failed',     TransactionStatus::Failure],
    ['incomplete', TransactionStatus::Pending],
]);

it('rejects malformed provider transactions at both external boundaries', function (
    string $providerClass,
    mixed $payload,
) {
    $provider = new $providerClass;
    $intent = minos_provider_intent($provider);
    Http::fake(['*' => Http::response($payload)]);

    expect(fn () => $provider->intent($intent))->toThrow(UnexpectedValueException::class);

    if (is_array($payload)) {
        expect(fn () => $provider->handle(new Notification('1', '1', $intent->method, $intent->payer, $payload)))
            ->toThrow(UnexpectedValueException::class);
    }
})->with([BePaid::class, Erip::class])->with([
    'invalid JSON' => ['invalid'],
    'missing transaction' => [[]],
    'missing reference' => [['transaction' => ['status' => 'successful']]],
    'missing status' => [['transaction' => ['uid' => 'remote-payment']]],
]);

it('propagates transport failures for all implemented online clients', function (string $providerClass) {
    $provider = new $providerClass;
    $intent = minos_provider_intent($provider);
    Http::fake(['*' => Http::response(['error' => 'Unavailable'], 503)]);

    expect(fn () => $provider->intent($intent))->toThrow(RequestException::class);
})->with([BePaid::class, Erip::class, WebPay::class]);

it('preserves ERIP instructions, checkout metadata and request expiry', function () {
    $provider = new Erip;
    $intent = minos_provider_intent($provider);
    $intent->method->settings = [
        ...$intent->method->settings,
        'instructions' => "First step\n\n Second step ",
    ];
    $intent->method->save();
    $intent->method->refresh();
    $payload = [
        'transaction' => [
            'uid' => 'erip-payment',
            'status' => 'pending',
            'erip' => [
                'qr_code' => 'qr',
                'account_number' => 'PAY-1',
                'instruction' => [' First -> Second '],
                'service_no_erip' => '12345',
                'banks' => ['bank'],
            ],
        ],
    ];
    Http::fake(['*' => Http::response($payload)]);
    $result = $provider->intent($intent);

    expect($result->metadata)
        ->toBe([
            'qr_code' => 'qr',
            'account' => 'PAY-1',
            'instruction' => ['First', 'Second'],
            'service' => '12345',
            'banks' => ['bank'],
        ])
        ->and($result->expiresAt)
        ->not->toBeNull();
    Http::assertSent(
        fn (Request $request) => (
            $request['request']['tracking_id'] === $intent->id
            && $request['request']['amount'] === 1000
            && $request['request']['payment_method']['service_no'] === '12345'
            && $request['request']['payment_method']['instruction'] === ['First step', 'Second step']
        ),
    );
});

it('reuses saved cards across immediate and callback success with a full expiry day', function () {
    Date::setTestNow('2027-01-31 12:30:00 UTC');

    try {
        $provider = new BePaid;
        $intent = minos_provider_intent($provider);
        $payload = minos_card_response();
        Http::fake(['*' => Http::response($payload)]);
        $initial = $provider->intent($intent);
        $payload['persist'] = true;
        $callback = $provider->handle(new Notification('1', '1', $intent->method, $intent->payer, $payload));
        $card = CreditCard::query()->sole();

        expect($callback->metadata['payment_card_id'])
            ->toBe($initial->metadata['payment_card_id'])
            ->and($card->is_default)
            ->toBeTrue()
            ->and($card->sort)
            ->toBe(1)
            ->and($card->expires_at->format('Y-m-d H:i:s'))
            ->toBe('2028-02-29 23:59:59')
            ->and($card->token)
            ->toBe('card-token');

        $provider->handle(
            new Notification('2', '1', $intent->method, new Payer((string) Str::uuid(), 'customer'), $payload),
        );
        $other = minos_provider_intent($provider);
        $provider->handle(new Notification('3', '1', $other->method, $intent->payer, $payload));
        expect(CreditCard::query()->count())->toBe(3);
    } finally {
        Date::setTestNow();
    }
});

it('stores and lists checkout cards on the package connection', function () {
    config()->set('database.connections.minos', config('database.connections.sqlite'));
    config()->set('phpinnacle-minos.connection', 'minos');
    $this->artisan('migrate', [
        '--path' => realpath(__DIR__ . '/../../database/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();
    $provider = new BePaid;
    $intent = minos_provider_intent($provider);
    $intent->method->settings = [...$intent->method->settings, 'public_key' => 'public'];
    Http::fake(['*' => Http::response(minos_card_response())]);
    $cardId = $provider->intent($intent)->metadata['payment_card_id'];
    $card = CreditCard::query()->findOrFail($cardId);

    $schema = $provider->schema($intent->method, $intent->payer);
    expect($schema->properties[1]->enum)
        ->toBe([$cardId])
        ->and($card->getConnection()->getName())
        ->toBe('minos')
        ->and($card->method->getConnection()->getName())
        ->toBe('minos')
        ->and(CreditCard::list($intent->method->id, $intent->payer)->modelKeys())
        ->toBe([$cardId]);
});

it('rejects invalid external card expiry instead of repairing it', function () {
    $provider = new BePaid;
    $intent = minos_provider_intent($provider);
    $payload = minos_card_response();
    $payload['persist'] = true;
    $payload['transaction']['credit_card']['exp_month'] = 13;

    expect(fn () => $provider->handle(new Notification('1', '1', $intent->method, $intent->payer, $payload)))
        ->toThrow(UnexpectedValueException::class);
    expect(CreditCard::query()->count())->toBe(0);
});

it('treats a successful response without a card token as a payment without card persistence', function () {
    $provider = new BePaid;
    $intent = minos_provider_intent($provider);
    $payload = ['transaction' => ['uid' => 'remote-payment', 'status' => 'successful'], 'persist' => true];
    $result = $provider->handle(new Notification('1', '1', $intent->method, $intent->payer, $payload));
    expect($result->status)
        ->toBe(TransactionStatus::Success)
        ->and($result->metadata['payment_card_id'])
        ->toBeNull()
        ->and(CreditCard::query()->count())
        ->toBe(0);
});

it('rejects incomplete and tampered WebPay notifications before interpreting them', function (array $payload) {
    $provider = new WebPay;
    $intent = minos_provider_intent($provider);

    expect(fn () => $provider->handle(new Notification('1', '1', $intent->method, $intent->payer, $payload)))
        ->toThrow(PaymentDenied::class);
})->with([
    'missing signature' => [[]],
    'missing fields' => [['wsb_signature' => 'signature']],
    'invalid signature shape' => [['wsb_signature' => []]],
]);

it('maps signed WebPay outcomes without changing the signed amount representation', function (
    string $code,
    TransactionStatus $status,
) {
    $payload = [
        'batch_timestamp' => '123',
        'currency_id' => 'USD',
        'amount' => '10.00',
        'payment_method' => 'cc',
        'order_id' => 'provider-order',
        'site_order_id' => 'merchant-order',
        'transaction_id' => 'remote-payment',
        'payment_type' => $code,
        'rrn' => 'reference',
        'wsb_signature' => md5('123USD10.00ccprovider-ordermerchant-orderremote-payment' . $code . 'referencesecret'),
    ];
    $provider = new WebPay;
    $intent = minos_provider_intent($provider);
    $result = $provider->handle(new Notification('1', '1', $intent->method, $intent->payer, $payload));
    expect($result->status)->toBe($status)->and($result->externalId)->toBe('remote-payment');
    $payload['amount'] = '10.0';
    expect(new RequestSigner('secret')->verify($payload))->toBeFalse();
})->with([
    ['1',  TransactionStatus::Success],
    ['4',  TransactionStatus::Success],
    ['5',  TransactionStatus::Failure],
    ['7',  TransactionStatus::Failure],
    ['9',  TransactionStatus::Failure],
    ['11', TransactionStatus::Failure],
    ['2',  TransactionStatus::Pending],
]);

it('rejects invalid JSON from WebPay instead of returning a pending result', function () {
    $provider = new WebPay;
    $intent = minos_provider_intent($provider);
    Http::fake(['*' => Http::response('invalid')]);
    expect(fn () => $provider->intent($intent))->toThrow(UnexpectedValueException::class);
});

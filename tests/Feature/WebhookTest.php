<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\CreditCard;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\IntentLine;
use PHPinnacle\Minos\Models\Payer;
use PHPinnacle\Minos\Models\Source;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Payments\BePaid;
use PHPinnacle\Minos\Payments\Erip;
use PHPinnacle\Minos\Payments\WebPay;
use PHPinnacle\Money\Money;
use Tests\TestCase;

uses(TestCase::class);

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
});

function webhook_transaction(BePaid|Erip|WebPay $provider): Transaction
{
    $method = $provider->define(['shop_id' => 'shop', 'secret_key' => 'secret']);
    $method->save();

    $transaction = Transaction::payment(new Intent(
        id: (string) Str::uuid(),
        number: 'PAY-001',
        description: 'Order payment',
        method: $method,
        source: new Source('00000000-0000-0000-0000-000000000001', 'order'),
        payer: new Payer('00000000-0000-0000-0000-000000000002', 'customer'),
        instrument: null,
        lines: [new IntentLine('Item', 1, new Money(1000, 'USD'))],
    ));
    $transaction->handle(new Continuation(
        TransactionStatus::Pending,
        externalId: 'remote-1',
        expiresAt: now()->subDay(),
    ));

    return $transaction;
}

function webhook_payload(Transaction $transaction): array
{
    return ['transaction' => [
        'uid' => 'remote-1',
        'tracking_id' => $transaction->id,
        'status' => 'successful',
        'amount' => 1000,
        'currency' => 'USD',
    ]];
}

it('reconciles a late BePaid success through the current provider state', function () {
    $transaction = webhook_transaction(new BePaid);
    Http::preventStrayRequests();
    Http::fake([
        'https://gateway.bepaid.by/transactions/remote-1' => Http::response(webhook_payload($transaction)),
    ]);

    $notification = webhook_payload($transaction);
    $notification['transaction']['status'] = 'failed';

    $this
        ->withHeaders(['Authorization' => 'Basic ' . base64_encode('shop:secret')])
        ->postJson(route('minos.transaction.notify', $transaction->id), $notification)
        ->assertNoContent();

    expect($transaction->refresh()->status)->toBe(TransactionStatus::Pending);
    expect(DB::table('jobs')->count())->toBe(1);
    Queue::connection('database')->pop('payments')->fire();

    expect($transaction->refresh()->status)->toBe(TransactionStatus::Success);
    Http::assertSentCount(1);
});

it('rejects an unauthenticated or mismatched BePaid webhook before reconciliation', function (
    array $changes,
    ?string $credentials,
) {
    $transaction = webhook_transaction(new BePaid);
    $payload = array_replace_recursive(webhook_payload($transaction), $changes);

    if ($credentials !== null) {
        $this->withHeaders(['Authorization' => 'Basic ' . base64_encode($credentials)]);
    }

    $this->postJson(route('minos.transaction.notify', $transaction->id), $payload)
        ->assertForbidden();

    expect(DB::table('jobs')->count())->toBe(0);
    expect($transaction->refresh()->status)->toBe(TransactionStatus::Pending);
})->with([
    'missing credentials' => [[], null],
    'wrong merchant' => [[], 'other:secret'],
    'wrong password' => [[], 'shop:other'],
    'wrong tracking ID' => [['transaction' => ['tracking_id' => 'other']], 'shop:secret'],
    'wrong provider ID' => [['transaction' => ['uid' => 'other']], 'shop:secret'],
    'wrong amount' => [['transaction' => ['amount' => 2000]], 'shop:secret'],
    'wrong currency' => [['transaction' => ['currency' => 'EUR']], 'shop:secret'],
    'missing status' => [['transaction' => ['status' => null]], 'shop:secret'],
    'malformed transaction' => [['transaction' => 'invalid'], 'shop:secret'],
]);

it('correlates an authenticated BePaid notification after a lost initial response', function () {
    $transaction = webhook_transaction(new BePaid);
    $transaction->external_id = null;
    $transaction->save();

    $this
        ->withHeaders(['Authorization' => 'Basic ' . base64_encode('shop:secret')])
        ->postJson(route('minos.transaction.notify', $transaction->id), webhook_payload($transaction))
        ->assertNoContent();

    expect($transaction->refresh()->external_id)->toBe('remote-1');
    expect($transaction->status)->toBe(TransactionStatus::Pending);
    expect(DB::table('jobs')->count())->toBe(1);
});

it('preserves saved card details from an authenticated BePaid notification', function () {
    $transaction = webhook_transaction(new BePaid);
    $payload = webhook_payload($transaction);
    $payload['transaction']['credit_card'] = [
        'token' => 'saved-card-token',
        'exp_month' => 10,
        'exp_year' => 2028,
    ];

    $this
        ->withHeaders(['Authorization' => 'Basic ' . base64_encode('shop:secret')])
        ->postJson(route('minos.transaction.notify', $transaction->id) . '?persist=1', $payload)
        ->assertNoContent();

    expect(CreditCard::query()->sole()->customer_id)->toBe('00000000-0000-0000-0000-000000000002');
    expect(DB::table('jobs')->count())->toBe(1);
});

it('applies an authenticated late ERIP success', function () {
    $transaction = webhook_transaction(new Erip);

    $this
        ->withHeaders(['Authorization' => 'Basic ' . base64_encode('shop:secret')])
        ->postJson(route('minos.transaction.notify', $transaction->id), webhook_payload($transaction))
        ->assertNoContent();

    expect($transaction->refresh()->status)->toBe(TransactionStatus::Success);
    expect(DB::table('jobs')->count())->toBe(0);
});

it('applies a signed late WebPay success for the matching operation', function () {
    $transaction = webhook_transaction(new WebPay);
    $payload = [
        'batch_timestamp' => '123',
        'currency_id' => 'USD',
        'amount' => '10.00',
        'payment_method' => 'cc',
        'order_id' => 'provider-order',
        'site_order_id' => 'PAY-001',
        'transaction_id' => 'remote-1',
        'payment_type' => '1',
        'rrn' => 'reference',
        'wsb_signature' => md5('123USD10.00ccprovider-orderPAY-001remote-11referencesecret'),
    ];

    $mismatched = $payload;
    $mismatched['site_order_id'] = 'OTHER';
    $mismatched['wsb_signature'] = md5('123USD10.00ccprovider-orderOTHERremote-11referencesecret');

    $this->post(route('minos.transaction.notify', $transaction->id), $mismatched)->assertForbidden();

    $this->post(route('minos.transaction.notify', $transaction->id), $payload)->assertNoContent();

    expect($transaction->refresh()->status)->toBe(TransactionStatus::Success);
});

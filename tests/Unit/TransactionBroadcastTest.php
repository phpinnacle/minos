<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPinnacle\Minos\Events\TransactionBroadcast;
use PHPinnacle\Minos\Events\TransactionCreated;
use PHPinnacle\Minos\Events\TransactionUpdated;
use PHPinnacle\Minos\Listeners\BroadcastTransaction;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\IntentLine;
use PHPinnacle\Minos\Models\Payer;
use PHPinnacle\Minos\Models\Source;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Payments\Cash;
use PHPinnacle\Money\Money;
use Tests\TestCase;

uses(TestCase::class);

function minos_broadcast_payment(): Transaction
{
    $method = new Cash()->define();
    $method->save();

    return Transaction::payment(new Intent(
        id: (string) Str::uuid(),
        number: 'PAY-BROADCAST',
        description: 'Broadcast payment',
        method: $method,
        source: new Source('00000000-0000-0000-0000-000000000001', 'order'),
        payer: new Payer('00000000-0000-0000-0000-000000000002', 'customer'),
        instrument: null,
        lines: [new IntentLine('Item', 1, new Money(1000, 'USD'))],
    ));
}

beforeEach(function () {
    (require __DIR__ . '/../../database/migrations/create_minos_tables.php')->up();
    Event::listen([TransactionCreated::class, TransactionUpdated::class], BroadcastTransaction::class);
    Event::fake([TransactionBroadcast::class]);
});

it('broadcasts a complete pending checkout update without arbitrary metadata', function () {
    $payment = minos_broadcast_payment();
    $payment->handle(Continuation::pending('remote-payment', [
        'redirect' => 'https://checkout.example.test/1',
        'receipt' => 'https://checkout.example.test/receipt',
        'message' => 'Continue payment',
        'qr_code' => 'checkout-qr',
        'account' => 'PAY-BROADCAST',
        'instruction' => ['Choose a bank', 'Confirm payment'],
        'service' => '12345',
        'banks' => ['internal-bank-data'],
        'private_token' => 'secret-value',
    ]));

    $broadcasts = Event::dispatched(TransactionBroadcast::class)->map(fn (array $args) => $args[0])->all();
    $broadcast = $broadcasts[1];
    $payload = $broadcast->broadcastWith();

    expect($broadcasts)
        ->toHaveCount(2)
        ->and($broadcast->broadcastOn()->name)
        ->toBe('private-minos.transactions.' . $payment->id)
        ->and($broadcast->broadcastAs())
        ->toBe('minos.transaction.changed')
        ->and($payload['root'])
        ->toMatchArray([
            'id' => $payment->id,
            'number' => 'PAY-BROADCAST',
            'amount' => 1000,
            'currency' => 'USD',
            'type' => 'payment',
            'status' => 'pending',
            'version' => $payment->version,
            'captured_amount' => 0,
            'refunded_amount' => 0,
            'received_amount' => 0,
            'capturable_amount' => null,
            'refundable_amount' => null,
        ])
        ->and($payload['operation']['redirect_url'])
        ->toBe('https://checkout.example.test/1')
        ->and($payload['operation']['receipt_url'])
        ->toBe('https://checkout.example.test/receipt')
        ->and($payload['operation']['message'])
        ->toBe('Continue payment')
        ->and($payload['operation']['qr_code'])
        ->toBe('checkout-qr')
        ->and($payload['operation']['account'])
        ->toBe('PAY-BROADCAST')
        ->and($payload['operation']['instruction'])
        ->toBe(['Choose a bank', 'Confirm payment'])
        ->and($payload['operation']['service'])
        ->toBe('12345')
        ->and($payload['operation']['status'])
        ->toBe('pending')
        ->and($payload['operation']['updated_at'])
        ->toBe($payment->updated_at->toIso8601String())
        ->and(json_encode($payload))
        ->not->toContain('secret-value', '00000000-0000-0000-0000-000000000002', 'internal-bank-data');
});

it('includes the updated root balance with a child refund', function () {
    $payment = minos_broadcast_payment()->handle(Continuation::success());
    $refund = $payment->refund('REF-BROADCAST', new Money(200, 'USD'), 'Returned item');
    $refund->handle(Continuation::success());

    $broadcast = Event::dispatched(TransactionBroadcast::class)->last()[0];
    $payload = $broadcast->broadcastWith();

    expect($broadcast->broadcastOn()->name)
        ->toBe('private-minos.transactions.' . $payment->id)
        ->and($payload['root']['version'])
        ->toBe($payment->refresh()->version)
        ->and($payload['root']['captured_amount'])
        ->toBe(1000)
        ->and($payload['root']['refunded_amount'])
        ->toBe(200)
        ->and($payload['root']['received_amount'])
        ->toBe(800)
        ->and($payload['root']['refundable_amount'])
        ->toBe(800)
        ->and($payload['operation']['id'])
        ->toBe($refund->id)
        ->and($payload['operation']['parent_id'])
        ->toBe($payment->id)
        ->and($payload['operation']['reason'])
        ->toBe('Returned item')
        ->and($payload['operation']['status'])
        ->toBe('success');
});

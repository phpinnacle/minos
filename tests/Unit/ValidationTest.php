<?php

use Illuminate\Support\Facades\Validator;
use PHPinnacle\Minos\Rules\PaymentParts;
use PHPinnacle\Minos\Rules\PaymentScheme;
use PHPinnacle\Money\Money;
use Tests\TestCase;

uses(TestCase::class);

it('rejects malformed installment definitions before calculating their total', function (mixed $parts) {
    expect(Validator::make(['parts' => $parts], ['parts' => [new PaymentParts]])->fails())->toBeTrue();
})->with([
    'scalar row' => [[['value' => 100, 'delay' => 0], 'invalid']],
    'missing ratio' => [[['value' => 100, 'delay' => 0], ['delay' => 1]]],
    'negative ratio' => [[['value' => -10, 'delay' => 0], ['value' => 110, 'delay' => 1]]],
    'fractional ratio' => [[['value' => 49.5, 'delay' => 0], ['value' => 50.5, 'delay' => 1]]],
    'missing delay' => [[['value' => 100]]],
    'negative delay' => [[['value' => 100, 'delay' => -1]]],
    'incorrect total' => [[['value' => 50, 'delay' => 0]]],
]);

it('accepts integer form values and associative repeater row keys', function () {
    $parts = ['first' => ['value' => '40', 'delay' => '0'], 'second' => ['value' => '60', 'delay' => '30']];
    expect(Validator::make(['parts' => $parts], ['parts' => [new PaymentParts]])->passes())->toBeTrue();
});

it('validates scheme inputs without accepting invalid amount and date shapes', function (mixed $scheme) {
    expect(Validator::make(['scheme' => $scheme], ['scheme' => [new PaymentScheme]])->fails())->toBeTrue();
})->with([
    'scalar row' => [['invalid']],
    'boolean amount' => [[['amount' => true, 'date' => '2026-01-01']]],
    'missing currency' => [[['amount' => ['amount' => '1.00'], 'date' => '2026-01-01']]],
    'missing amount' => [[['amount' => ['currency' => 'USD'], 'date' => '2026-01-01']]],
    'array date' => [[['amount' => ['amount' => '1.00', 'currency' => 'USD'], 'date' => []]]],
    'invalid date' => [[['amount' => ['amount' => '1.00', 'currency' => 'USD'], 'date' => 'invalid']]],
    'mixed currencies' => [[
        ['amount' => ['amount' => '1.00', 'currency' => 'USD'], 'date' => '2026-01-01'],
        ['amount' => ['amount' => '1.00', 'currency' => 'EUR'], 'date' => '2026-01-02'],
    ]],
]);

it('validates totals with custom form fields and typed money amounts', function () {
    $rule = new PaymentScheme(new Money(1000, 'USD'), amountField: 'price', dateField: 'sale_at');
    $parts = [['price' => new Money(1000, 'USD'), 'sale_at' => '2026-01-01']];
    expect(Validator::make(['scheme' => $parts], ['scheme' => [$rule]])->passes())->toBeTrue();
    $parts[0]['price'] = new Money(999, 'USD');
    expect(Validator::make(['scheme' => $parts], ['scheme' => [$rule]])->fails())->toBeTrue();
});

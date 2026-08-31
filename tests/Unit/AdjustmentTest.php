<?php

use PHPinnacle\Minos\Enums\AdjustmentType;
use PHPinnacle\Minos\Models\Adjustment;
use PHPinnacle\Money\Money;

it('subtracts the discount when it is smaller than the amount', function () {
    $adjustment = Adjustment::discount('Sale', new Money(300, 'BYN'));

    expect($adjustment->apply(new Money(1000, 'BYN'))->amount)->toBe(700);
});

it('clamps the result to zero when the discount exceeds the amount', function () {
    $adjustment = Adjustment::discount('Sale', new Money(1500, 'BYN'));

    $result = $adjustment->apply(new Money(1000, 'BYN'));

    expect($result->isZero())->toBeTrue();
});

it('clamps the result to zero when the discount exactly equals the amount', function () {
    $adjustment = Adjustment::discount('Sale', new Money(1000, 'BYN'));

    $result = $adjustment->apply(new Money(1000, 'BYN'));

    expect($result->isZero())->toBeTrue();
});

it('adds the adjustment amount for non-discount types', function (string $factory, AdjustmentType $expectedType) {
    $adjustment = Adjustment::{$factory}('Label', new Money(200, 'BYN'));

    expect($adjustment->type)
        ->toBe($expectedType)
        ->and($adjustment->apply(new Money(1000, 'BYN'))->amount)
        ->toBe(1200);
})->with([
    'shipping' => ['shipping', AdjustmentType::Shipping],
    'tax' => ['tax', AdjustmentType::Tax],
    'fee' => ['fee', AdjustmentType::Fee],
]);

it('stores the label and optional description', function () {
    $adjustment = Adjustment::discount('Sale', new Money(100, 'BYN'), 'Seasonal discount');

    expect($adjustment->label)->toBe('Sale')->and($adjustment->description)->toBe('Seasonal discount');
});

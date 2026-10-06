<?php

use Carbon\CarbonImmutable;
use PHPinnacle\Minos\Models\PaymentPart;
use PHPinnacle\Money\Money;

it('increases an installment amount without changing its due date or original amount', function () {
    $date = CarbonImmutable::parse('2026-01-15');
    $original = new PaymentPart(new Money(500, 'BYN'), $date);

    $updated = $original->add(new Money(200, 'BYN'));

    expect($updated->amount->amount)
        ->toBe(700)
        ->and($updated->date->toDateString())
        ->toBe('2026-01-15')
        ->and($original->amount->amount)
        ->toBe(500);
});

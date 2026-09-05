<?php

use Carbon\CarbonImmutable;
use PHPinnacle\Minos\Models\PaymentPlan;
use PHPinnacle\Money\Money;

it('splits the price into parts according to the configured ratios and delays', function () {
    $plan = payment_plan([
        ['value' => 50, 'delay' => 0],
        ['value' => 25, 'delay' => 15],
        ['value' => 25, 'delay' => 30],
    ]);

    $scheme = $plan->scheme(new Money(1000, 'BYN'), CarbonImmutable::parse('2026-01-01'));

    expect($scheme->count())
        ->toBe(3)
        ->and($scheme->total()->amount)
        ->toBe(1000)
        ->and($scheme->parts->get(0)->amount->amount)
        ->toBe(500)
        ->and($scheme->parts->get(0)->date->toDateString())
        ->toBe('2026-01-01')
        ->and($scheme->parts->get(1)->amount->amount)
        ->toBe(250)
        ->and($scheme->parts->get(1)->date->toDateString())
        ->toBe('2026-01-16')
        ->and($scheme->parts->get(2)->amount->amount)
        ->toBe(250)
        ->and($scheme->parts->get(2)->date->toDateString())
        ->toBe('2026-01-31');
});

it('redistributes the remaining minor unit when the ratios do not divide evenly', function () {
    $plan = payment_plan([
        ['value' => 1, 'delay' => 0],
        ['value' => 1, 'delay' => 10],
        ['value' => 1, 'delay' => 20],
    ]);

    $scheme = $plan->scheme(new Money(100, 'BYN'), CarbonImmutable::parse('2026-01-01'));

    expect($scheme->parts->get(0)->amount->amount)
        ->toBe(34)
        ->and($scheme->parts->get(1)->amount->amount)
        ->toBe(33)
        ->and($scheme->parts->get(2)->amount->amount)
        ->toBe(33)
        ->and($scheme->total()->amount)
        ->toBe(100);
});

it('reorders parts chronologically even when the configuration lists a later delay first', function () {
    $plan = payment_plan([
        ['value' => 25, 'delay' => 30],
        ['value' => 75, 'delay' => 0],
    ]);

    $scheme = $plan->scheme(new Money(1000, 'BYN'), CarbonImmutable::parse('2026-01-01'));

    expect($scheme->parts->first()->amount->amount)
        ->toBe(750)
        ->and($scheme->parts->first()->date->toDateString())
        ->toBe('2026-01-01')
        ->and($scheme->parts->last()->amount->amount)
        ->toBe(250)
        ->and($scheme->parts->last()->date->toDateString())
        ->toBe('2026-01-31')
        ->and($scheme->starts()->toDateString())
        ->toBe('2026-01-01')
        ->and($scheme->expires()->toDateString())
        ->toBe('2026-01-31');
});

function payment_plan(array $parts): PaymentPlan
{
    $plan = new PaymentPlan;
    $plan->forceFill(['parts' => $parts]);

    return $plan;
}

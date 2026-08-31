<?php

use Carbon\CarbonImmutable;
use PHPinnacle\Minos\Models\PaymentPart;
use PHPinnacle\Minos\Models\PaymentScheme;
use PHPinnacle\Money\Money;

it('resolves mixed array and instance items and sorts them by date', function () {
    $scheme = PaymentScheme::create([
        ['amount' => new Money(300, 'BYN'), 'date' => '2026-02-01'],
        new PaymentPart(new Money(200, 'BYN'), CarbonImmutable::parse('2026-01-01')),
    ]);

    expect($scheme->count())
        ->toBe(2)
        ->and($scheme->parts->first()->amount->amount)
        ->toBe(200)
        ->and($scheme->parts->last()->amount->amount)
        ->toBe(300);
});

it('creates a single-part scheme via once', function () {
    $scheme = PaymentScheme::once(new Money(1000, 'BYN'), CarbonImmutable::parse('2026-01-01'));

    expect($scheme->count())
        ->toBe(1)
        ->and($scheme->total()->amount)
        ->toBe(1000)
        ->and($scheme->starts()->toDateString())
        ->toBe('2026-01-01');
});

it('adjusts only the earliest part and returns a new instance', function () {
    $scheme = PaymentScheme::create([
        new PaymentPart(new Money(500, 'BYN'), CarbonImmutable::parse('2026-01-01')),
        new PaymentPart(new Money(500, 'BYN'), CarbonImmutable::parse('2026-02-01')),
    ]);

    $adjusted = $scheme->adjust(new Money(200, 'BYN'));

    expect($adjusted)
        ->not
        ->toBe($scheme)
        ->and($adjusted->parts->first()->amount->amount)
        ->toBe(700)
        ->and($adjusted->parts->last()->amount->amount)
        ->toBe(500)
        ->and($scheme->parts->first()->amount->amount)
        ->toBe(500);
});

it('returns itself unchanged when adjusting an empty scheme', function () {
    $empty = PaymentScheme::create([]);

    $result = $empty->adjust(new Money(100, 'BYN'));

    expect($result)->toBe($empty)->and($result->count())->toBe(0);
});

it('returns null starts and expires for an empty scheme', function () {
    $empty = PaymentScheme::create([]);

    expect($empty->starts())->toBeNull()->and($empty->expires())->toBeNull();
});

it('sums the total and reports the first and last dates for a populated scheme', function () {
    $scheme = PaymentScheme::create([
        new PaymentPart(new Money(300, 'BYN'), CarbonImmutable::parse('2026-01-01')),
        new PaymentPart(new Money(400, 'BYN'), CarbonImmutable::parse('2026-02-01')),
        new PaymentPart(new Money(300, 'BYN'), CarbonImmutable::parse('2026-03-01')),
    ]);

    expect($scheme->count())
        ->toBe(3)
        ->and($scheme->total()->amount)
        ->toBe(1000)
        ->and($scheme->starts()->toDateString())
        ->toBe('2026-01-01')
        ->and($scheme->expires()->toDateString())
        ->toBe('2026-03-01');
});

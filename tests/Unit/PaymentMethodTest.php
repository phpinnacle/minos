<?php

use PHPinnacle\Minos\Models\PaymentMethod;
use PHPinnacle\Minos\Payments\BePaid;
use PHPinnacle\Minos\Payments\Cash;
use Tests\TestCase;

uses(TestCase::class);

it('derives whether the method is online from its provider abilities', function () {
    expect(PaymentMethod::define(new BePaid)->isOnline())
        ->toBeTrue()
        ->and(PaymentMethod::define(new Cash)->isOnline())
        ->toBeFalse();
});

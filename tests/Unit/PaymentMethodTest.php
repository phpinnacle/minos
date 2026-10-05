<?php

use PHPinnacle\Minos\Forms\MethodSelect;
use PHPinnacle\Minos\Models\Model;
use PHPinnacle\Minos\Models\PaymentMethod;
use PHPinnacle\Minos\Payments\BePaid;
use PHPinnacle\Minos\Payments\Cash;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    (require __DIR__ . '/../../database/migrations/create_minos_tables.php')->up();
});

it('derives whether the method is online from its provider abilities', function () {
    expect(PaymentMethod::define(new BePaid)->isOnline())
        ->toBeTrue()
        ->and(PaymentMethod::define(new Cash)->isOnline())
        ->toBeFalse();
});

it('looks up an active method by its provider key', function () {
    $method = new Cash()->define();
    $method->save();

    expect(PaymentMethod::get(new Cash)->is($method))->toBeTrue();
});

it('filters the default method by the same online requirement as the selector', function () {
    $method = new Cash()->define();
    $method->is_default = true;
    $method->save();

    expect(PaymentMethod::default(false)?->id)->toBe($method->id)->and(PaymentMethod::default(true))->toBeNull();
    expect(MethodSelect::make()->online(true)->withDefault()->getDefaultState())
        ->toBeNull()
        ->and(MethodSelect::make()->online(false)->withDefault()->getDefaultState())
        ->toBe($method->id);
});

it('uses the package connection or the application default', function () {
    config()->set('phpinnacle-minos.connection', 'package');
    $model = new Model;
    expect($model->getConnectionName())->toBe('package');
    config()->set('phpinnacle-minos.connection', null);
    expect($model->getConnection()->getName())->toBe(config('database.default'));
});

<?php

namespace PHPinnacle\Minos\Payments;

use BackedEnum;
use Illuminate\Contracts\Support\Htmlable;
use OpenApi\Attributes as OA;
use PHPinnacle\Minos\Contracts\PaymentProvider;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\Notification;
use PHPinnacle\Minos\Models\Payer;
use PHPinnacle\Minos\Models\PaymentMethod;

abstract class Base implements PaymentProvider
{
    public function getColor(): string|array|null
    {
        return 'primary';
    }

    public function getDescription(): string|Htmlable|null
    {
        return null;
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return null;
    }

    public function getLabel(): string|Htmlable|null
    {
        return null;
    }

    public function validate(array $settings): bool
    {
        return true;
    }

    public function define(array $settings = []): PaymentMethod
    {
        return PaymentMethod::define($this, $settings);
    }

    public function form(): array
    {
        return [];
    }

    public function abilities(): array
    {
        return [];
    }

    public function schema(PaymentMethod $method, Payer $payer): ?OA\Schema
    {
        return null;
    }

    public function intent(Intent $intent): Continuation
    {
        return Continuation::pending();
    }

    public function handle(Notification $notification): Continuation
    {
        return Continuation::success();
    }
}

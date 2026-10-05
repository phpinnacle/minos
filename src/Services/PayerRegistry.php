<?php

namespace PHPinnacle\Minos\Services;

use Illuminate\Container\Attributes\Singleton;
use PHPinnacle\Minos\Contracts\PaymentPayer;

#[Singleton]
class PayerRegistry
{
    /** @var array<string, PaymentPayer> */
    private array $payers = [];

    public function register(PaymentPayer ...$payers): void
    {
        foreach ($payers as $payer) {
            $this->payers[$payer->type()] = $payer;
        }
    }

    public function get(string $type): ?PaymentPayer
    {
        return $this->payers[$type] ?? null;
    }
}

<?php

namespace PHPinnacle\Minos\Services;

use Countable;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;
use PHPinnacle\Minos\Contracts\PaymentProvider;

#[Singleton]
class ProviderRegistry implements Countable
{
    /**
     * @var array<class-string<PaymentProvider>, PaymentProvider>
     */
    private array $items = [];

    public function register(PaymentProvider ...$providers): void
    {
        foreach ($providers as $provider) {
            $this->items[$provider->key()] = $provider;
        }
    }

    public function get(string $key): ?PaymentProvider
    {
        return $this->items[$key] ?? null;
    }

    /**
     * @return Collection<class-string<PaymentProvider>, PaymentProvider>
     */
    public function all(): Collection
    {
        return collect($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}

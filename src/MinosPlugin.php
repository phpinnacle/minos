<?php

namespace PHPinnacle\Minos;

use Filament\Contracts\Plugin;
use Filament\Panel;
use PHPinnacle\Minos\Contracts\PaymentPayer;
use PHPinnacle\Minos\Contracts\TransactionSource;
use PHPinnacle\Minos\Services\PayerRegistry;
use PHPinnacle\Minos\Services\SourceRegistry;

class MinosPlugin implements Plugin
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly PayerRegistry $payers,
    ) {}

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        // @mago-expect lint:inline-variable-return
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public function getId(): string
    {
        return 'phpinnacle/minos';
    }

    public function sources(TransactionSource ...$sources): static
    {
        $this->sources->register(...$sources);

        return $this;
    }

    public function payers(PaymentPayer ...$payers): static
    {
        $this->payers->register(...$payers);

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            Resources\Methods\MethodResource::class,
            Resources\Plans\PlanResource::class,
            Resources\Transactions\TransactionResource::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}

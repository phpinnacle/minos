<?php

namespace PHPinnacle\Minos;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Illuminate\Support\Arr;
use PHPinnacle\Minos\Contracts\PaymentProvider;
use PHPinnacle\Minos\Services\ProviderRegistry;

class MinosPlugin implements Plugin
{
    use EvaluatesClosures;

    private array $providers = [];

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function boot(Panel $panel): void {}

    public function getId(): string
    {
        return 'phpinnacle/minos';
    }

    public function loadProviders(ProviderRegistry $registry): void
    {
        foreach ($this->providers as $provider) {
            $registry->register(...Arr::wrap($this->evaluate($provider)));
        }
    }

    public function providers(Closure|PaymentProvider ...$providers): self
    {
        $this->providers = [
            ...$this->providers,
            ...$providers,
        ];

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            Resources\Methods\MethodResource::class,
            Resources\Plans\PlanResource::class,
        ]);
    }
}

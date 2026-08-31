<?php

namespace PHPinnacle\Minos\Forms;

use Filament\Forms\Components\Select;
use PHPinnacle\Minos\Contracts\PaymentProvider;
use PHPinnacle\Minos\Services\ProviderRegistry;

class ProviderSelect extends Select
{
    public static function getDefaultName(): string
    {
        return 'provider';
    }

    public function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('phpinnacle-minos::forms.provider.label'))
            ->placeholder(__('phpinnacle-minos::forms.provider.placeholder'))
            ->options(
                fn (ProviderRegistry $registry) => $registry
                    ->all()
                    ->map(fn (PaymentProvider $provider) => $provider->getLabel())
                    ->all(),
            )
            ->required();
    }
}

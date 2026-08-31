<?php

namespace PHPinnacle\Minos\Payments;

use Filament\Support\Colors\Color;
use PHPinnacle\Minos\Enums\Ability;

class Cash extends Base
{
    public function abilities(): array
    {
        return [
            Ability::Offline,
        ];
    }

    public function getColor(): array
    {
        return Color::Green;
    }

    public function getDescription(): string
    {
        return __('phpinnacle-minos::providers.cash.description');
    }

    public function getIcon(): string
    {
        return 'phosphor-money';
    }

    public function getLabel(): string
    {
        return __('phpinnacle-minos::providers.cash.label');
    }

    public function key(): string
    {
        return 'cash';
    }
}

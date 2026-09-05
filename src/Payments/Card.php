<?php

namespace PHPinnacle\Minos\Payments;

use Filament\Support\Colors\Color;
use PHPinnacle\Minos\Enums\Ability;

class Card extends Base
{
    public function key(): string
    {
        return 'card';
    }

    public function getColor(): array
    {
        return Color::Yellow;
    }

    public function getIcon(): string
    {
        return 'phosphor-credit-card';
    }

    public function getDescription(): string
    {
        return __('phpinnacle-minos::providers.card.description');
    }

    public function getLabel(): string
    {
        return __('phpinnacle-minos::providers.card.label');
    }

    public function abilities(): array
    {
        return [
            Ability::Offline,
        ];
    }
}

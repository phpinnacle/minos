<?php

namespace PHPinnacle\Minos\Contracts;

use Filament\Schemas\Components\Component;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use PHPinnacle\Minos\Models\PaymentMethod;

interface PaymentProvider extends HasColor, HasDescription, HasIcon, HasLabel, PaymentGateway
{
    /** @return array<Component> */
    public function form(): array;

    /** @param array<string, mixed> $settings */
    public function validate(array $settings): bool;

    /** @param array<string, mixed> $settings */
    public function define(array $settings = []): PaymentMethod;
}

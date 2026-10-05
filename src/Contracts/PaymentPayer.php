<?php

namespace PHPinnacle\Minos\Contracts;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;
use PHPinnacle\Minos\Models\Transaction;

interface PaymentPayer
{
    public function type(): string;

    /** @return array<Component|Action|ActionGroup|string|Htmlable> */
    public function render(Transaction $transaction): array;
}

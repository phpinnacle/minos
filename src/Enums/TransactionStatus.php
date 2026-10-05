<?php

namespace PHPinnacle\Minos\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum TransactionStatus: string implements HasColor, HasIcon, HasLabel
{
    case Success = 'success';
    case Failure = 'failure';
    case Pending = 'pending';
    case Cancel = 'cancel';

    public function getLabel(): string
    {
        return __('phpinnacle-minos::enums.transaction_status.' . $this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Success => 'success',
            self::Failure => 'danger',
            self::Cancel => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Success => 'phosphor-check-circle',
            self::Failure => 'phosphor-x-circle',
            self::Pending => 'phosphor-clock',
            self::Cancel => 'phosphor-prohibit',
        };
    }
}

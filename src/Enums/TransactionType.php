<?php

namespace PHPinnacle\Minos\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum TransactionType: string implements HasColor, HasIcon, HasLabel
{
    case PAYMENT = 'payment';
    case AUTHORIZE = 'authorize';
    case VOID = 'void';
    case CAPTURE = 'capture';
    case REFUND = 'refund';

    public function getLabel(): string
    {
        return __('phpinnacle-minos::enums.transaction_type.' . $this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PAYMENT, self::CAPTURE => 'success',
            self::AUTHORIZE => 'info',
            self::VOID => 'gray',
            self::REFUND => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::PAYMENT => 'phosphor-credit-card',
            self::AUTHORIZE => 'phosphor-lock',
            self::VOID => 'phosphor-lock-open',
            self::CAPTURE => 'phosphor-hand-coins',
            self::REFUND => 'phosphor-arrow-u-up-left',
        };
    }
}

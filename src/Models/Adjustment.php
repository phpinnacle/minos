<?php

namespace PHPinnacle\Minos\Models;

use PHPinnacle\Minos\Enums\AdjustmentType;
use PHPinnacle\Money\Money;

readonly class Adjustment
{
    public function __construct(
        public string $label,
        public AdjustmentType $type,
        public Money $amount,
        public ?string $description = null,
    ) {}

    public static function discount(string $label, Money $amount, ?string $description = null): self
    {
        return new self($label, AdjustmentType::Discount, $amount, $description);
    }

    public static function shipping(string $label, Money $amount, ?string $description = null): self
    {
        return new self($label, AdjustmentType::Shipping, $amount, $description);
    }

    public static function tax(string $label, Money $amount, ?string $description = null): self
    {
        return new self($label, AdjustmentType::Tax, $amount, $description);
    }

    public static function fee(string $label, Money $amount, ?string $description = null): self
    {
        return new self($label, AdjustmentType::Fee, $amount, $description);
    }

    public function apply(Money $amount): Money
    {
        return match ($this->type) {
            AdjustmentType::Discount => $amount->gt($this->amount) ? $amount->sub($this->amount) : $amount->zeroize(),
            default => $amount->add($this->amount),
        };
    }
}

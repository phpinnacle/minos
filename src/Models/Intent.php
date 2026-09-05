<?php

namespace PHPinnacle\Minos\Models;

use PHPinnacle\Minos\Contracts\Instrument;
use PHPinnacle\Minos\Enums\AdjustmentType;
use PHPinnacle\Money\Money;

readonly class Intent
{
    public function __construct(
        public string $id,
        public string $number,
        public string $description,
        public PaymentMethod $method,
        public Source $source,
        public Payer $payer,
        public ?Instrument $instrument,
        /** @var IntentLine[] */
        public array $lines = [],
        /** @var Adjustment[] */
        public array $adjustments = [],
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public ?string $notifyUrl = null,
        public bool $recurring = false,
    ) {}

    public function total(): Money
    {
        $total = Money::sum(...array_map(fn (IntentLine $line) => $line->price->mul($line->qty), $this->lines));

        foreach ($this->adjustments as $adjustment) {
            $total = $adjustment->apply($total);
        }

        return $total;
    }

    public function tax(string $currency): Money
    {
        return $this->calculate($currency, AdjustmentType::Tax);
    }

    public function discount(string $currency): Money
    {
        return $this->calculate($currency, AdjustmentType::Discount);
    }

    public function find(AdjustmentType $type): ?Adjustment
    {
        return array_find($this->adjustments, fn (Adjustment $adjustment) => $adjustment->type === $type);
    }

    public function calculate(string $currency, AdjustmentType $type): Money
    {
        $amount = Money::zero($currency);

        foreach ($this->adjustments as $adjustment) {
            if ($adjustment->type !== $type) {
                continue;
            }

            $amount = $amount->add($adjustment->amount);
        }

        return $amount;
    }
}

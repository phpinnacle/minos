<?php

namespace PHPinnacle\Minos\Models;

use PHPinnacle\Money\Money;

readonly class IntentLine
{
    public function __construct(
        public string $title,
        public int $qty,
        public Money $price,
        public ?string $description = null,
        public ?string $image = null,
    ) {}
}

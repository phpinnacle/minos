<?php

namespace PHPinnacle\Minos\Instruments;

use PHPinnacle\Minos\Contracts\Instrument;

class CardToken implements Instrument
{
    public function __construct(
        public string $id,
        public ?string $verificationValue = null,
    ) {}

    public static function create(string $id): self
    {
        return new self($id);
    }
}

<?php

namespace PHPinnacle\Minos\Models;

use DateTimeImmutable;
use SensitiveParameter;

readonly class CardDetails
{
    public function __construct(
        #[SensitiveParameter]
        public string $token,
        public DateTimeImmutable $expiresAt,
        public ?string $product = null,
        public ?string $country = null,
        public ?string $brand = null,
        public ?string $subbrand = null,
        public ?string $bin = null,
        public ?string $mask = null,
    ) {}
}

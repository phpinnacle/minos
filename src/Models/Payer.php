<?php

namespace PHPinnacle\Minos\Models;

readonly class Payer
{
    public function __construct(
        public string $id,
        public string $type,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $language = null,
        public ?string $country = null,
        public ?string $ipAddress = null,
    ) {}
}

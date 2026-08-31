<?php

namespace PHPinnacle\Minos\Models;

use DateTimeInterface;
use PHPinnacle\Minos\Enums\Decision;

class Continuation
{
    public function __construct(
        public Decision $decision,
        public ?string $externalId = null,
        public ?DateTimeInterface $expiresAt = null,
        public array $response = [],
        public array $metadata = [],
    ) {}

    public static function failure(?string $id = null, array $metadata = []): self
    {
        return new self(Decision::Failure, $id, metadata: $metadata);
    }

    public static function pending(?string $id = null, array $metadata = []): self
    {
        return new self(Decision::Pending, $id, metadata: $metadata);
    }

    public static function success(?string $id = null, array $metadata = []): self
    {
        return new self(Decision::Success, $id, metadata: $metadata);
    }
}

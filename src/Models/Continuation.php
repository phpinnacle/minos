<?php

namespace PHPinnacle\Minos\Models;

use DateTimeInterface;
use PHPinnacle\Minos\Enums\Decision;

class Continuation
{
    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public Decision $decision,
        public ?string $externalId = null,
        public ?DateTimeInterface $expiresAt = null,
        public array $response = [],
        public array $metadata = [],
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public static function success(?string $id = null, array $metadata = []): self
    {
        return new self(Decision::Success, $id, metadata: $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function failure(?string $id = null, array $metadata = []): self
    {
        return new self(Decision::Failure, $id, metadata: $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function pending(?string $id = null, array $metadata = []): self
    {
        return new self(Decision::Pending, $id, metadata: $metadata);
    }
}

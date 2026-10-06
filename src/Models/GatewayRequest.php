<?php

namespace PHPinnacle\Minos\Models;

use DateTimeImmutable;

readonly class GatewayRequest
{
    /**
     * @param array<string, mixed> $payload Stored only in an encrypted queue job; may contain provider-encrypted card data.
     * @param array<string, mixed> $metadata Non-sensitive transaction metadata.
     */
    public function __construct(
        public array $payload,
        public DateTimeImmutable $replayUntil,
        public array $metadata = [],
    ) {}
}

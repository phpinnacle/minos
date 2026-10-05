<?php

namespace PHPinnacle\Minos\Models;

use DateTimeImmutable;

readonly class GatewayRequest
{
    /** @param array<string, mixed> $payload Safe to persist; excludes credentials and card verification data. */
    public function __construct(
        public array $payload,
        public DateTimeImmutable $replayUntil,
    ) {}
}

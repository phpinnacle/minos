<?php

namespace PHPinnacle\Minos\Models;

readonly class Notification
{
    public function __construct(
        public string $id,
        public string $order,
        public PaymentMethod $method,
        public Payer $payer,
        public array $payload,
    ) {}
}

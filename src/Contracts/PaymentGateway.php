<?php

namespace PHPinnacle\Minos\Contracts;

use OpenApi\Attributes as OA;
use PHPinnacle\Minos\Enums\Ability;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\Notification;
use PHPinnacle\Minos\Models\Payer;
use PHPinnacle\Minos\Models\PaymentMethod;

interface PaymentGateway
{
    public function key(): string;

    /** @return list<Ability> */
    public function abilities(): array;

    public function schema(PaymentMethod $method, Payer $payer): ?OA\Schema;

    public function intent(Intent $intent): Continuation;

    public function handle(Notification $notification): Continuation;
}

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
    /** @return list<Ability> */
    public function abilities(): array;

    public function handle(Notification $notification): Continuation;

    public function intent(Intent $intent): Continuation;

    public function key(): string;

    public function schema(PaymentMethod $method, Payer $payer): ?OA\Schema;
}

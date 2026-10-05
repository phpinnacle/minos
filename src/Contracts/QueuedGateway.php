<?php

namespace PHPinnacle\Minos\Contracts;

use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\GatewayRequest;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\Transaction;

interface QueuedGateway extends PaymentGateway
{
    /** Prepare an immutable, persistable payment or authorization request without making remote calls. */
    public function prepare(Intent $intent): GatewayRequest;

    /** Derive an immutable, persistable capture, void or refund request without making remote calls. */
    public function derive(Transaction $transaction): GatewayRequest;

    /**
     * Replays must use the transaction ID as the provider's idempotency key.
     * @param array<string, mixed> $payload
     */
    public function execute(Transaction $transaction, array $payload): Continuation;

    /** Retrieve the current state of this operation, not an aggregate payment snapshot. */
    public function synchronize(Transaction $transaction): Continuation;
}

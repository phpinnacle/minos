<?php

namespace PHPinnacle\Minos\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use PHPinnacle\Minos\Models\Transaction;

final readonly class TransactionUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Transaction $transaction,
    ) {}
}

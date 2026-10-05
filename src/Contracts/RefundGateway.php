<?php

namespace PHPinnacle\Minos\Contracts;

use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Transaction;

interface RefundGateway extends PaymentGateway
{
    public function refund(Transaction $transaction): Continuation;
}

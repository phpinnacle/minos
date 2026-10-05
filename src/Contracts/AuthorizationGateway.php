<?php

namespace PHPinnacle\Minos\Contracts;

use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\Transaction;

interface AuthorizationGateway extends PaymentGateway
{
    public function authorize(Intent $intent): Continuation;

    public function capture(Transaction $transaction): Continuation;

    public function void(Transaction $transaction): Continuation;
}

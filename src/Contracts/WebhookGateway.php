<?php

namespace PHPinnacle\Minos\Contracts;

use Illuminate\Http\Request;
use PHPinnacle\Minos\Models\Transaction;

interface WebhookGateway extends PaymentGateway
{
    public function acceptsWebhook(Transaction $transaction, Request $request): bool;
}

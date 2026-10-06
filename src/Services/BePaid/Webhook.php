<?php

namespace PHPinnacle\Minos\Services\BePaid;

use Illuminate\Http\Request;
use PHPinnacle\Minos\Models\Transaction;

class Webhook
{
    public static function accepts(Transaction $transaction, Request $request): bool
    {
        $settings = $transaction->method->settings;
        $user = $request->getUser();
        $password = $request->getPassword();

        if (
            !is_string($user)
            || !is_string($password)
            || !hash_equals((string) $settings['shop_id'], $user)
            || !hash_equals($settings['secret_key'], $password)
        ) {
            return false;
        }

        $payload = $request->input('transaction');

        if (!is_array($payload)) {
            return false;
        }

        $uid = $payload['uid'] ?? null;
        $trackingId = $payload['tracking_id'] ?? null;
        $status = $payload['status'] ?? null;

        return (
            is_string($uid)
            && $uid !== ''
            && is_string($trackingId)
            && is_string($status)
            && $status !== ''
            && hash_equals($transaction->id, $trackingId)
            && ($transaction->external_id === null || hash_equals($transaction->external_id, $uid))
            && ($payload['amount'] ?? null) === $transaction->amount->amount
            && ($payload['currency'] ?? null) === $transaction->currency
        );
    }
}

<?php

namespace PHPinnacle\Minos\Listeners;

use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Enums\TransactionType;
use PHPinnacle\Minos\Events\TransactionBroadcast;
use PHPinnacle\Minos\Events\TransactionCreated;
use PHPinnacle\Minos\Events\TransactionUpdated;
use PHPinnacle\Minos\Models\Transaction;

class BroadcastTransaction
{
    public function handle(TransactionCreated|TransactionUpdated $event): void
    {
        $rootId = $event->transaction->root()->id;
        $payload = $event
            ->transaction
            ->getConnection()
            ->transaction(function () use ($event, $rootId) {
                $root = Transaction::query()->lockForUpdate()->findOrFail($rootId);
                $transaction = $rootId === $event->transaction->id
                    ? $root
                    : Transaction::query()->with('method')->findOrFail($event->transaction->id);
                $transaction->loadMissing('method');
                $captured = $root->captured();
                $refunded = $root->refunded();

                return [
                    'root' => [
                        'id' => $root->id,
                        'number' => $root->number,
                        'amount' => $root->amount->amount,
                        'currency' => $root->currency,
                        'type' => $root->type->value,
                        'status' => $root->status->value,
                        'version' => $root->version,
                        'captured_amount' => $captured->amount,
                        'refunded_amount' => $refunded->amount,
                        'received_amount' => $captured->sub($refunded)->amount,
                        'capturable_amount' => $this->capturable($root),
                        'refundable_amount' => $this->refundable($root),
                    ],
                    'operation' => [
                        'id' => $transaction->id,
                        'parent_id' => $transaction->parent_id,
                        'number' => $transaction->number,
                        'description' => $transaction->description,
                        'reason' => $transaction->reason,
                        'type' => $transaction->type->value,
                        'status' => $transaction->status->value,
                        'amount' => $transaction->amount->amount,
                        'currency' => $transaction->currency,
                        'external_id' => $transaction->external_id,
                        'method_name' => $transaction->method->name,
                        'created_at' => $transaction->created_at->toIso8601String(),
                        'updated_at' => $transaction->updated_at->toIso8601String(),
                        'processed_at' => $transaction->processed_at?->toIso8601String(),
                        'expires_at' => $transaction->expires_at?->toIso8601String(),
                        'capturable_amount' => $this->capturable($transaction),
                        'refundable_amount' => $this->refundable($transaction),
                        'redirect_url' => $this->metadataText($transaction, 'redirect'),
                        'receipt_url' => $this->metadataText($transaction, 'receipt'),
                        'message' => $this->metadataText($transaction, 'message'),
                        'qr_code' => $this->metadataText($transaction, 'qr_code'),
                        'account' => $this->metadataText($transaction, 'account'),
                        'instruction' => $transaction->metadata['instruction'] ?? null,
                        'service' => $this->metadataText($transaction, 'service'),
                    ],
                ];
            });

        TransactionBroadcast::dispatch($rootId, $payload);
    }

    private function capturable(Transaction $transaction): ?int
    {
        return $transaction->status === TransactionStatus::Success && $transaction->type === TransactionType::AUTHORIZE
            ? $transaction->capturable()->amount
            : null;
    }

    private function refundable(Transaction $transaction): ?int
    {
        return $transaction->status === TransactionStatus::Success
        && in_array($transaction->type, [TransactionType::PAYMENT, TransactionType::CAPTURE], true)
            ? $transaction->refundable()->amount
            : null;
    }

    private function metadataText(Transaction $transaction, string $key): ?string
    {
        $value = $transaction->metadata[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}

<?php

namespace PHPinnacle\Minos\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class TransactionBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /**
     * @param array{
     *     root: array{
     *         id: string, number: string, amount: int, currency: string,
     *         type: string, status: string, version: int,
     *         captured_amount: int, refunded_amount: int, received_amount: int,
     *         capturable_amount: int|null, refundable_amount: int|null
     *     },
     *     operation: array{
     *         id: string, parent_id: string|null, number: string, description: string,
     *         reason: string|null, type: string, status: string, amount: int,
     *         currency: string, external_id: string|null, method_name: string,
     *         created_at: string, updated_at: string, processed_at: string|null, expires_at: string|null,
     *         capturable_amount: int|null, refundable_amount: int|null,
     *         redirect_url: string|null, receipt_url: string|null, message: string|null,
     *         qr_code: string|null, account: string|null, instruction: list<string>|null, service: string|null
     *     }
     * } $payload
     */
    public function __construct(
        public string $rootId,
        public array $payload,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('minos.transactions.' . $this->rootId);
    }

    public function broadcastAs(): string
    {
        return 'minos.transaction.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}

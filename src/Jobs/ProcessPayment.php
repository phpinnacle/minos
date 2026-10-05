<?php

namespace PHPinnacle\Minos\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Models\GatewayRequest;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Services\PaymentManager;
use RuntimeException;

class ProcessPayment implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $transactionId,
        public ?GatewayRequest $request = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        $transaction = $this->transaction();
        $key = 'minos:' . $transaction->getConnection()->getName() . ':' . $transaction->root()->id;

        return [new WithoutOverlapping($key)
            ->shared()
            ->releaseAfter(10)
            ->expireAfter(120)];
    }

    public function handle(PaymentManager $payments): void
    {
        $transaction = $this->transaction();
        $version = $transaction->root()->refresh()->version;
        $gateway = $payments->gateway($transaction->method);

        if ($this->request !== null && $transaction->status !== TransactionStatus::Pending) {
            return;
        }

        if ($this->request === null) {
            $transaction->synchronize($gateway->synchronize($transaction), $version);

            return;
        }

        if ($this->request->replayUntil < now()) {
            throw new RuntimeException('The provider replay window expired. Reconcile before retrying this operation.');
        }

        $transaction->handle($gateway->execute($transaction, $this->request->payload), $version);
    }

    private function transaction(): Transaction
    {
        return Transaction::query()->findOrFail($this->transactionId);
    }
}

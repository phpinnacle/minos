<?php

namespace PHPinnacle\Minos\Infolists;

use Closure;
use Filament\Actions\Action;
use Filament\Infolists\Components\Entry;
use Filament\Support\Concerns\CanBeContained;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use PHPinnacle\Minos\Contracts\AuthorizationGateway;
use PHPinnacle\Minos\Contracts\QueuedGateway;
use PHPinnacle\Minos\Contracts\RefundGateway;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Enums\TransactionType;
use PHPinnacle\Minos\Infolists\Actions\CancelTransactionAction;
use PHPinnacle\Minos\Infolists\Actions\CaptureTransactionAction;
use PHPinnacle\Minos\Infolists\Actions\RefundTransactionAction;
use PHPinnacle\Minos\Infolists\Actions\VoidTransactionAction;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Resources\Transactions\TransactionResource;
use PHPinnacle\Minos\Services\ProviderRegistry;

class TransactionHistoryEntry extends Entry
{
    use CanBeContained;

    protected string $view = 'phpinnacle-minos::infolists.transaction-history-entry';

    private int $limit = 10;

    private Closure|bool $manageWhen = false;

    private ?string $relationshipName = null;

    public function manageWhen(Closure|bool $condition): static
    {
        $this->manageWhen = $condition;

        return $this;
    }

    public function relationship(string $name): static
    {
        $this->relationshipName = $name;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    /** @return EloquentCollection<int, Transaction> */
    public function getTransactions(): EloquentCollection
    {
        if (!TransactionResource::canViewAny()) {
            return new EloquentCollection;
        }

        if ($this->relationshipName !== null) {
            $record = $this->getRecord();

            if (!$record instanceof Model || !$record->exists) {
                return new EloquentCollection;
            }

            $transactions = $record
                ->{$this->relationshipName}()
                ->whereNull('parent_id')
                ->with(['method', 'children.method', 'children.children.method'])
                ->latest()
                ->limit($this->limit + 1)
                ->get();
        } else {
            $state = $this->getState();
            $transactions = new EloquentCollection($state instanceof Collection ? $state->all() : $state ?? []);
            $transactions = $transactions
                ->filter(fn (Model $transaction) => $transaction->getAttribute('parent_id') === null)
                ->sortByDesc('created_at')
                ->take($this->limit + 1)
                ->values();
            $transactions->loadMissing(['method', 'children.method', 'children.children.method']);
        }

        return $transactions
            ->filter(TransactionResource::canView(...))
            ->values();
    }

    public function getTransactionUrl(Transaction $transaction): ?string
    {
        return (
            TransactionResource::canView($transaction)
                ? TransactionResource::getUrl('view', ['record' => $transaction])
                : null
        );
    }

    /** @return Collection<int, Transaction> */
    public function getOperations(Transaction $transaction): Collection
    {
        return $transaction
            ->children
            ->flatMap(fn (Transaction $child) => [$child, ...$child->children->all()])
            ->filter(TransactionResource::canView(...))
            ->sortBy('created_at')
            ->values();
    }

    public function canOperate(Transaction $transaction, string $operation, ProviderRegistry $providers): bool
    {
        if (
            !$this->evaluate($this->manageWhen)
            || !TransactionResource::canView($transaction)
            || !Gate::allows('update', $transaction)
        ) {
            return false;
        }

        if ($operation === 'cancel') {
            return $transaction->status === TransactionStatus::Pending && !$transaction->method->isOnline();
        }

        if ($transaction->status !== TransactionStatus::Success) {
            return false;
        }

        if ($transaction->method->isOnline()) {
            $provider = $providers->get($transaction->method->provider);

            if (!$provider instanceof QueuedGateway) {
                return false;
            }

            if ($operation === 'refund' && !$provider instanceof RefundGateway) {
                return false;
            }

            if (in_array($operation, ['capture', 'void'], true) && !$provider instanceof AuthorizationGateway) {
                return false;
            }
        }

        return match ($operation) {
            'capture', 'void' => $transaction->type === TransactionType::AUTHORIZE
                && $transaction->capturable()->isSome(),
            'refund' => in_array($transaction->type, [TransactionType::PAYMENT, TransactionType::CAPTURE], true)
                && $transaction->refundable()->isSome(),
            default => false,
        };
    }

    /** @return array<Action> */
    public function getDefaultActions(): array
    {
        return [
            CancelTransactionAction::make()->history($this),
            CaptureTransactionAction::make()->history($this),
            VoidTransactionAction::make()->history($this),
            RefundTransactionAction::make()->history($this),
        ];
    }

    /** @param array{transaction?: string} $arguments */
    public function transactionFromArguments(array $arguments): Transaction
    {
        $transactionId = $arguments['transaction'] ?? null;

        if ($transactionId === null || !$this->evaluate($this->manageWhen)) {
            throw new ModelNotFoundException;
        }

        $allowed = $this
            ->getTransactions()
            ->take($this->limit)
            ->flatMap(fn (Transaction $transaction) => [$transaction, ...$this->getOperations($transaction)->all()])
            ->contains(fn (Transaction $transaction) => $transaction->getKey() === $transactionId);

        if (!$allowed) {
            throw new ModelNotFoundException;
        }

        return Transaction::query()->with('method')->findOrFail($transactionId);
    }
}

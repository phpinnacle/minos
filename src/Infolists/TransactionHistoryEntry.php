<?php

namespace PHPinnacle\Minos\Infolists;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\Entry;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPinnacle\Minos\Contracts\AuthorizationGateway;
use PHPinnacle\Minos\Contracts\QueuedGateway;
use PHPinnacle\Minos\Contracts\RefundGateway;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Enums\TransactionType;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Resources\Transactions\TransactionResource;
use PHPinnacle\Minos\Services\PaymentManager;
use PHPinnacle\Minos\Services\ProviderRegistry;
use PHPinnacle\Money\Forms\MoneyInput;
use PHPinnacle\Money\Money;

class TransactionHistoryEntry extends Entry
{
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
        if (!$this->evaluate($this->manageWhen) || !TransactionResource::canView($transaction)) {
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
            $this->cancelAction(),
            $this->operationAction('capture'),
            $this->operationAction('void'),
            $this->operationAction('refund'),
        ];
    }

    private function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel transaction')
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading('Cancel transaction')
            ->modalDescription('Mark this pending offline transaction as canceled?')
            ->modalSubmitActionLabel('Cancel transaction')
            ->action(function (array $arguments, ProviderRegistry $providers) {
                $transaction = $this->transactionFromArguments($arguments);

                if (!$this->canOperate($transaction, 'cancel', $providers)) {
                    Notification::make()->title('This operation is no longer available.')->danger()->send();

                    return;
                }

                $transaction->handle(new Continuation(TransactionStatus::Cancel));

                if ($transaction->status !== TransactionStatus::Cancel) {
                    Notification::make()->title('This operation is no longer available.')->danger()->send();

                    return;
                }

                Notification::make()->title('Transaction canceled')->success()->send();
            });
    }

    private function operationAction(string $operation): Action
    {
        return Action::make($operation)
            ->label(fn (array $arguments) => $this->actionLabel($operation, $arguments))
            ->color(match ($operation) {
                'void' => 'gray',
                'refund' => 'warning',
                default => 'primary',
            })
            ->link()
            ->modalHeading(fn (array $arguments) => $this->actionLabel($operation, $arguments))
            ->modalDescription(fn (array $arguments) => $this->transactionFromArguments($arguments)->method->isOnline()
                ? 'The request will be sent to the payment provider.'
                : 'Record this only after the operation has been completed outside this system.')
            ->modalSubmitActionLabel(fn (array $arguments) => $this->actionLabel($operation, $arguments))
            ->schema(
                fn (array $arguments, ProviderRegistry $providers) => $this->operationForm(
                    $operation,
                    $arguments,
                    $providers,
                ),
            )
            ->action(function (
                Action $action,
                array $arguments,
                array $data,
                PaymentManager $payments,
                ProviderRegistry $providers,
            ) use ($operation) {
                $transaction = $this->transactionFromArguments($arguments);

                if (!$this->canOperate($transaction, $operation, $providers)) {
                    Notification::make()->title('This operation is no longer available.')->danger()->send();

                    return;
                }

                $this->performOperation($operation, $transaction, $data, $action, $payments);
            });
    }

    /** @param array{transaction: string} $arguments */
    private function actionLabel(string $operation, array $arguments): string
    {
        $online = $this->transactionFromArguments($arguments)->method->isOnline();

        return match ($operation) {
            'capture' => $online ? 'Capture funds' : 'Record capture',
            'void' => $online ? 'Release hold' : 'Record hold release',
            'refund' => $online ? 'Refund payment' : 'Record refund',
            default => throw new InvalidArgumentException('Unsupported transaction operation.'),
        };
    }

    /**
     * @param array{transaction: string} $arguments
     * @return array<MoneyInput|TextInput>
     */
    private function operationForm(string $operation, array $arguments, ProviderRegistry $providers): array
    {
        $transaction = $this->transactionFromArguments($arguments);

        if (!$this->canOperate($transaction, $operation, $providers)) {
            throw new ModelNotFoundException;
        }

        $available = $operation === 'refund' ? $transaction->refundable() : $transaction->capturable();
        $fields = [
            MoneyInput::make('amount')
                ->currencies([$transaction->currency])
                ->default($available)
                ->lesser($available)
                ->required(),
        ];

        if ($operation === 'refund') {
            $fields[] = TextInput::make('reason')
                ->label('Reason for refund')
                ->required()
                ->maxLength(255);
        }

        return $fields;
    }

    /** @param array{amount: Money, reason?: string} $data */
    private function performOperation(
        string $operation,
        Transaction $transaction,
        array $data,
        Action $action,
        PaymentManager $payments,
    ): void {
        $number = Str::upper($operation) . '-' . Str::ulid();
        $amount = $data['amount'];
        $available = $operation === 'refund' ? $transaction->refundable() : $transaction->capturable();

        if (
            $amount->currency !== $transaction->currency
            || $amount->amount <= 0
            || !$available->gt($amount, equal: true)
        ) {
            Notification::make()
                ->title("Enter an amount up to {$available->format()}.")
                ->danger()
                ->send();

            $action->halt();
        }

        $created = match ($operation) {
            'capture' => $payments->capture($transaction, $number, $amount),
            'void' => $payments->void($transaction, $number, $amount),
            'refund' => $payments->refund($transaction, $number, $amount, $data['reason']),
            default => throw new InvalidArgumentException('Unsupported transaction operation.'),
        };

        if (!$transaction->method->isOnline()) {
            $created->handle(Continuation::success());
        }

        Notification::make()
            ->title($transaction->method->isOnline() ? 'Operation requested' : 'Operation recorded')
            ->success()
            ->send();
    }

    /** @param array{transaction?: string} $arguments */
    private function transactionFromArguments(array $arguments): Transaction
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

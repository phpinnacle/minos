<?php

namespace PHPinnacle\Minos\Infolists\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use LogicException;
use PHPinnacle\Minos\Enums\TransactionType;
use PHPinnacle\Minos\Infolists\TransactionHistoryEntry;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Payments\Stripe;
use PHPinnacle\Minos\Services\PaymentManager;
use PHPinnacle\Minos\Services\ProviderRegistry;
use PHPinnacle\Money\Forms\MoneyInput;
use PHPinnacle\Money\Money;

abstract class TransactionOperationAction extends Action
{
    private TransactionHistoryEntry $history;

    abstract protected static function operation(): TransactionType;

    public static function getDefaultName(): ?string
    {
        return static::operation()->value;
    }

    public function history(TransactionHistoryEntry $history): static
    {
        $this->history = $history;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label($this->actionLabel(...))
            ->color(match (static::operation()) {
                TransactionType::VOID => 'gray',
                TransactionType::REFUND => 'warning',
                default => 'primary',
            })
            ->link()
            ->modalHeading($this->actionLabel(...))
            ->modalDescription($this->operationDescription(...))
            ->modalSubmitActionLabel($this->actionLabel(...))
            ->schema($this->operationForm(...))
            ->action(function (
                array $arguments,
                array $data,
                PaymentManager $payments,
                ProviderRegistry $providers,
            ) {
                $transaction = $this->history->transactionFromArguments($arguments);

                if (!$this->history->canOperate($transaction, static::operation()->value, $providers)) {
                    Notification::make()
                        ->title(__('phpinnacle-minos::resources.transaction.notifications.unavailable'))
                        ->danger()
                        ->send();

                    return;
                }

                $this->performOperation($transaction, $data, $payments);
            });
    }

    /** @param array{transaction: string} $arguments */
    private function actionLabel(array $arguments): string
    {
        $online = $this->history->transactionFromArguments($arguments)->method->isOnline();

        return match (static::operation()) {
            TransactionType::CAPTURE => $online
                ? __('phpinnacle-minos::resources.transaction.actions.capture')
                : __('phpinnacle-minos::resources.transaction.actions.record_capture'),
            TransactionType::VOID => $online
                ? __('phpinnacle-minos::resources.transaction.actions.void')
                : __('phpinnacle-minos::resources.transaction.actions.record_void'),
            TransactionType::REFUND => $online
                ? __('phpinnacle-minos::resources.transaction.actions.refund')
                : __('phpinnacle-minos::resources.transaction.actions.record_refund'),
            default => throw new LogicException('Unsupported transaction operation.'),
        };
    }

    /** @param array{transaction: string} $arguments */
    private function operationDescription(array $arguments): string
    {
        return $this->history->transactionFromArguments($arguments)->method->isOnline()
            ? __('phpinnacle-minos::resources.transaction.modals.online_operation')
            : __('phpinnacle-minos::resources.transaction.modals.offline_operation');
    }

    /**
     * @param array{transaction: string} $arguments
     * @return array<MoneyInput|TextInput>
     */
    private function operationForm(array $arguments, ProviderRegistry $providers): array
    {
        $transaction = $this->history->transactionFromArguments($arguments);

        if (!$this->history->canOperate($transaction, static::operation()->value, $providers)) {
            throw new ModelNotFoundException;
        }

        $available = static::operation() === TransactionType::REFUND
            ? $transaction->refundable()
            : $transaction->capturable();
        $amount = MoneyInput::make('amount')
            ->label(__('phpinnacle-minos::resources.transaction.fields.amount'))
            ->currencies([$transaction->currency])
            ->default($available)
            ->required();

        if (
            in_array(static::operation(), [TransactionType::CAPTURE, TransactionType::VOID], true)
            && $providers->get($transaction->method->provider) instanceof Stripe
        ) {
            $amount->equal($available)->readOnly();
        } else {
            $amount->lesser($available);
        }

        $fields = [$amount];

        if (static::operation() === TransactionType::REFUND) {
            $fields[] = TextInput::make('reason')
                ->label(__('phpinnacle-minos::resources.transaction.fields.reason'))
                ->required()
                ->maxLength(255);
        }

        return $fields;
    }

    /** @param array{amount: Money, reason?: string} $data */
    private function performOperation(Transaction $transaction, array $data, PaymentManager $payments): void
    {
        $operation = static::operation();
        $number = Str::upper($operation->value) . '-' . Str::ulid();
        $amount = $data['amount'];
        $available = $operation === TransactionType::REFUND ? $transaction->refundable() : $transaction->capturable();

        if (
            $amount->currency !== $transaction->currency
            || $amount->amount <= 0
            || !$available->gt($amount, equal: true)
        ) {
            Notification::make()
                ->title(__('phpinnacle-minos::resources.transaction.notifications.amount_limit', [
                    'amount' => $available->format(),
                ]))
                ->danger()
                ->send();

            $this->halt();
        }

        if ($transaction->method->isOnline()) {
            $this->createOperation($transaction, $number, $amount, $data, $payments);
        } else {
            $transaction
                ->getConnection()
                ->transaction(function () use ($transaction, $number, $amount, $data, $payments) {
                    $created = $this->createOperation($transaction, $number, $amount, $data, $payments);
                    $created->confirmManual();
                });
        }

        Notification::make()
            ->title(
                $transaction->method->isOnline()
                    ? __('phpinnacle-minos::resources.transaction.notifications.requested')
                    : __('phpinnacle-minos::resources.transaction.notifications.recorded'),
            )
            ->success()
            ->send();
    }

    /** @param array{amount: Money, reason?: string} $data */
    private function createOperation(
        Transaction $transaction,
        string $number,
        Money $amount,
        array $data,
        PaymentManager $payments,
    ): Transaction {
        return match (static::operation()) {
            TransactionType::CAPTURE => $payments->capture($transaction, $number, $amount),
            TransactionType::VOID => $payments->void($transaction, $number, $amount),
            TransactionType::REFUND => $payments->refund($transaction, $number, $amount, $data['reason']),
            default => throw new LogicException('Unsupported transaction operation.'),
        };
    }
}

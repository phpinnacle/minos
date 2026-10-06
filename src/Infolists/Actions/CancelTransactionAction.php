<?php

namespace PHPinnacle\Minos\Infolists\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Infolists\TransactionHistoryEntry;
use PHPinnacle\Minos\Models\Continuation;
use PHPinnacle\Minos\Services\ProviderRegistry;

class CancelTransactionAction extends Action
{
    private TransactionHistoryEntry $history;

    public static function getDefaultName(): ?string
    {
        return 'cancel';
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
            ->label(__('phpinnacle-minos::resources.transaction.actions.cancel'))
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(__('phpinnacle-minos::resources.transaction.actions.cancel'))
            ->modalDescription(__('phpinnacle-minos::resources.transaction.modals.cancel'))
            ->modalSubmitActionLabel(__('phpinnacle-minos::resources.transaction.actions.cancel'))
            ->action(function (array $arguments, ProviderRegistry $providers) {
                $transaction = $this->history->transactionFromArguments($arguments);

                if (!$this->history->canOperate($transaction, 'cancel', $providers)) {
                    Notification::make()
                        ->title(__('phpinnacle-minos::resources.transaction.notifications.unavailable'))
                        ->danger()
                        ->send();

                    return;
                }

                $transaction->handle(new Continuation(TransactionStatus::Cancel));

                if ($transaction->status !== TransactionStatus::Cancel) {
                    Notification::make()
                        ->title(__('phpinnacle-minos::resources.transaction.notifications.unavailable'))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('phpinnacle-minos::resources.transaction.notifications.canceled'))
                    ->success()
                    ->send();
            });
    }
}

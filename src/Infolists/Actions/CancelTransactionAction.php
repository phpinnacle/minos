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
            ->label('Cancel transaction')
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading('Cancel transaction')
            ->modalDescription('Mark this pending offline transaction as canceled?')
            ->modalSubmitActionLabel('Cancel transaction')
            ->action(function (array $arguments, ProviderRegistry $providers) {
                $transaction = $this->history->transactionFromArguments($arguments);

                if (!$this->history->canOperate($transaction, 'cancel', $providers)) {
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
}

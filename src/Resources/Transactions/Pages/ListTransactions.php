<?php

namespace PHPinnacle\Minos\Resources\Transactions\Pages;

use Filament\Resources\Pages\ListRecords;
use PHPinnacle\Minos\Resources\Transactions\TransactionResource;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    public function getTitle(): string
    {
        return '';
    }
}

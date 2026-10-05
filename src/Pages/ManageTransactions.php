<?php

namespace PHPinnacle\Minos\Pages;

use Filament\Resources\Pages\ManageRelatedRecords;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Minos\Resources\Transactions\TransactionResource;

abstract class ManageTransactions extends ManageRelatedRecords
{
    protected static string $relationship = 'paymentTransactions';

    protected static ?string $relatedResource = TransactionResource::class;

    /** @param array<string, mixed> $parameters */
    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof Model && static::getResource()::canView($record) && TransactionResource::canViewAny();
    }

    public static function getRelationshipTitle(): string
    {
        return TransactionResource::getPluralModelLabel();
    }

    public function getTitle(): string
    {
        return static::getRelationshipTitle();
    }
}

<?php

namespace PHPinnacle\Minos\Resources\Transactions\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Minos\Resources\Transactions\TransactionResource;

class OperationsRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    protected static ?string $relatedResource = TransactionResource::class;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('phpinnacle-minos::resources.transaction.sections.operations');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('phpinnacle-minos::resources.transaction.sections.operations'))
            ->emptyStateHeading(__('phpinnacle-minos::resources.transaction.empty.operations'))
            ->emptyStateDescription(null);
    }
}

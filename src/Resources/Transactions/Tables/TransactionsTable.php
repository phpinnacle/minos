<?php

namespace PHPinnacle\Minos\Resources\Transactions\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use PHPinnacle\Common\Tables\CreatedColumn;
use PHPinnacle\Common\Tables\UpdatedColumn;
use PHPinnacle\Minos\Enums\TransactionStatus;
use PHPinnacle\Minos\Enums\TransactionType;
use PHPinnacle\Minos\Resources\Transactions\TransactionResource;
use PHPinnacle\Money\Tables\MoneyColumn;
use PHPinnacle\Tempo\Filters\DateRangeFilter;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->heading(__('phpinnacle-minos::resources.transaction.label'))
            ->emptyStateIcon(TransactionResource::getNavigationIcon())
            ->emptyStateHeading(__('phpinnacle-minos::resources.transaction.empty.heading'))
            ->emptyStateDescription(__('phpinnacle-minos::resources.transaction.empty.description'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['method', 'parent']))
            ->columns(self::columns())
            ->recordActions([ViewAction::make()->iconButton()])
            ->defaultSort('created_at', 'desc')
            ->filtersFormColumns(2)
            ->filters(self::filters());
    }

    /** @return list<TextColumn> */
    private static function columns(): array
    {
        return [
            TextColumn::make('number')
                ->label(__('phpinnacle-minos::resources.transaction.fields.number'))
                ->searchable()
                ->sortable(),
            TextColumn::make('method.name')
                ->label(__('phpinnacle-minos::resources.transaction.fields.method'))
                ->badge()
                ->toggleable(),
            TextColumn::make('type')
                ->label(__('phpinnacle-minos::resources.transaction.fields.type'))
                ->badge()
                ->toggleable(),
            TextColumn::make('status')
                ->label(__('phpinnacle-minos::resources.transaction.fields.status'))
                ->badge()
                ->toggleable(),
            MoneyColumn::make('amount')
                ->label(__('phpinnacle-minos::resources.transaction.fields.amount'))
                ->sortable(),
            TextColumn::make('parent.number')
                ->label(__('phpinnacle-minos::resources.transaction.fields.parent'))
                ->toggleable(),
            TextColumn::make('external_id')
                ->label(__('phpinnacle-minos::resources.transaction.fields.external_id'))
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('expires_at')
                ->label(__('phpinnacle-minos::resources.transaction.fields.expires_at'))
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('processed_at')
                ->label(__('phpinnacle-minos::resources.transaction.fields.processed_at'))
                ->dateTime()
                ->sortable()
                ->toggleable(),
            CreatedColumn::make()->toggleable(isToggledHiddenByDefault: false),
            UpdatedColumn::make(),
        ];
    }

    /** @return list<BaseFilter> */
    private static function filters(): array
    {
        return [
            SelectFilter::make('method_id')
                ->label(__('phpinnacle-minos::resources.transaction.fields.method'))
                ->relationship('method', 'name')
                ->searchable()
                ->preload(),
            SelectFilter::make('type')
                ->label(__('phpinnacle-minos::resources.transaction.fields.type'))
                ->options(TransactionType::class),
            SelectFilter::make('status')
                ->label(__('phpinnacle-minos::resources.transaction.fields.status'))
                ->options(TransactionStatus::class),
            DateRangeFilter::make('expires_at')
                ->label(__('phpinnacle-minos::resources.transaction.fields.expires_at')),
            DateRangeFilter::make('processed_at')
                ->label(__('phpinnacle-minos::resources.transaction.fields.processed_at')),
            DateRangeFilter::createdAt(),
            DateRangeFilter::updatedAt(),
        ];
    }
}

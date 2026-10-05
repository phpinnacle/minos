<?php

namespace PHPinnacle\Minos\Resources\Transactions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use PHPinnacle\Minos\Enums\TransactionType;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Resources\Transactions\TransactionResource;
use PHPinnacle\Minos\Services\PayerRegistry;
use PHPinnacle\Minos\Services\SourceRegistry;
use PHPinnacle\Money\Money;

class TransactionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                self::operation(),
                self::balance(),
                self::details(),
            ]);
    }

    private static function operation(): Section
    {
        return Section::make(__('phpinnacle-minos::resources.transaction.sections.operation'))
            ->columns(3)
            ->schema(fn (Transaction $record, SourceRegistry $sources, PayerRegistry $payers) => [
                TextEntry::make('number')->label(__('phpinnacle-minos::resources.transaction.fields.number')),
                TextEntry::make('method.name')->label(__(
                    'phpinnacle-minos::resources.transaction.fields.method',
                )),
                self::money('amount'),
                TextEntry::make('type')
                    ->label(__('phpinnacle-minos::resources.transaction.fields.type'))
                    ->badge(),
                TextEntry::make('status')
                    ->label(__('phpinnacle-minos::resources.transaction.fields.status'))
                    ->badge(),
                TextEntry::make('description')
                    ->label(__('phpinnacle-minos::resources.transaction.fields.description'))
                    ->columnSpanFull(),
                TextEntry::make('reason')
                    ->label(__('phpinnacle-minos::resources.transaction.fields.reason'))
                    ->visible(fn (Transaction $record) => $record->type === TransactionType::REFUND)
                    ->columnSpanFull(),
                TextEntry::make('parent.number')
                    ->label(__('phpinnacle-minos::resources.transaction.fields.parent'))
                    ->visible(fn (Transaction $record) => $record->parent_id !== null)
                    ->url(fn (Transaction $record) => (
                        $record->parent !== null && TransactionResource::canView($record->parent)
                            ? TransactionResource::getUrl('view', ['record' => $record->parent])
                            : null
                    )),
                ...(
                    $sources->get($record->source_type)?->render($record) ?? [
                        self::reference('source', $record->source_id, $record->source_type),
                    ]
                ),
                ...(
                    $payers->get($record->payer_type)?->render($record) ?? [
                        self::reference('payer', $record->payer_id, $record->payer_type),
                    ]
                ),
            ]);
    }

    private static function balance(): Section
    {
        return Section::make(__('phpinnacle-minos::resources.transaction.sections.balance'))
            ->visible(fn (Transaction $record) => in_array(
                $record->type,
                [TransactionType::PAYMENT, TransactionType::AUTHORIZE, TransactionType::CAPTURE],
                true,
            ))
            ->columns(3)
            ->schema([
                self::money('captured')->state(fn (Transaction $record) => $record->captured()),
                self::money('refunded')->state(fn (Transaction $record) => $record->refunded()),
                self::money('received')->state(fn (Transaction $record) => $record->received()),
            ]);
    }

    private static function details(): Section
    {
        return Section::make(__('phpinnacle-minos::resources.transaction.sections.details'))
            ->columns(2)
            ->schema([
                TextEntry::make('id')
                    ->label(__('phpinnacle-minos::resources.transaction.fields.id'))
                    ->copyable(),
                TextEntry::make('external_id')
                    ->label(__('phpinnacle-minos::resources.transaction.fields.external_id'))
                    ->copyable(),
                TextEntry::make('created_at')
                    ->label(__('phpinnacle-minos::resources.transaction.fields.created_at'))
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label(__('phpinnacle-minos::resources.transaction.fields.updated_at'))
                    ->dateTime(),
                TextEntry::make('expires_at')
                    ->label(__('phpinnacle-minos::resources.transaction.fields.expires_at'))
                    ->dateTime(),
                TextEntry::make('processed_at')
                    ->label(__('phpinnacle-minos::resources.transaction.fields.processed_at'))
                    ->dateTime(),
            ]);
    }

    private static function money(string $name): TextEntry
    {
        return TextEntry::make($name)
            ->label(__('phpinnacle-minos::resources.transaction.fields.' . $name))
            ->formatStateUsing(fn (Money $state) => $state->decimal() . ' ' . $state->currency);
    }

    private static function reference(string $name, string $id, string $type): TextEntry
    {
        return TextEntry::make($name . '_reference')
            ->label(__('phpinnacle-minos::resources.transaction.fields.' . $name))
            ->state($id)
            ->helperText($type);
    }
}

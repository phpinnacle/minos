<?php

namespace PHPinnacle\Minos\Resources\Methods\Tables;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;
use PHPinnacle\Common\Filters\ActiveFilter;
use PHPinnacle\Common\Tables\ActiveColumn;
use PHPinnacle\Common\Tables\CreatedColumn;
use PHPinnacle\Common\Tables\DefaultColumn;
use PHPinnacle\Common\Tables\UpdatedColumn;
use PHPinnacle\Minos\Contracts\PaymentProvider;
use PHPinnacle\Minos\Models\PaymentMethod;
use PHPinnacle\Minos\Resources\Methods\MethodResource;
use PHPinnacle\Minos\Services\ProviderRegistry;
use PHPinnacle\Tempo\Filters\DateRangeFilter;

class MethodsTable
{
    public static function configure(Table $table): Table
    {
        $actions = app(ProviderRegistry::class)
            ->all()
            ->map(self::action(...))
            ->all();

        return $table
            ->heading(__('phpinnacle-minos::resources.payment_method.pages.list'))
            ->emptyStateIcon(MethodResource::getNavigationIcon())
            ->emptyStateHeading(__('phpinnacle-minos::resources.payment_method.empty.heading'))
            ->emptyStateDescription(__('phpinnacle-minos::resources.payment_method.empty.description'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('phpinnacle-minos::resources.payment_method.fields.name'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('provider')
                    ->label(__('phpinnacle-minos::resources.payment_method.fields.provider'))
                    ->getStateUsing(fn (
                        ProviderRegistry $registry,
                        PaymentMethod $record,
                    ) => $registry->get($record->provider))
                    ->badge(),
                DefaultColumn::make()
                    ->action(fn (PaymentMethod $record) => $record->toggleDefault()),
                ActiveColumn::make()
                    ->action(fn (PaymentMethod $record) => $record->toggleActive()),
                CreatedColumn::make(),
                UpdatedColumn::make(),
            ])
            ->headerActions($actions)
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label(__('phpinnacle-minos::resources.payment_method.actions.delete')),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('phpinnacle-minos::resources.payment_method.actions.update'))
                    ->iconButton(),
                DeleteAction::make()
                    ->label(__('phpinnacle-minos::resources.payment_method.actions.delete'))
                    ->iconButton(),
            ])
            ->filters([
                ActiveFilter::make(),
                DateRangeFilter::createdAt(),
                DateRangeFilter::updatedAt(),
            ])
            ->defaultSort('sort')
            ->reorderable('sort');
    }

    private static function action(PaymentProvider $provider): Action
    {
        $key = str($provider::class)->snake()->replace('\\', '.');
        $icon = $provider->getIcon();
        $label = $provider->getLabel();
        $color = $provider->getColor();

        return CreateAction::make('payment_method.' . $key)
            ->modalHeading(__('phpinnacle-minos::resources.payment_method.modals.create.heading', [
                'provider' => $label,
            ]))
            ->modalDescription(__('phpinnacle-minos::resources.payment_method.modals.create.description'))
            ->modalIcon($icon)
            ->modalIconColor($color)
            ->label($label)
            ->color($color)
            ->icon($icon)
            ->schema(fn (Schema $schema) => $schema->operation('create')->components($provider->form()))
            ->action(function (array $data) use ($provider) {
                if (!$provider->validate($data)) {
                    throw new InvalidArgumentException;
                }

                $method = $provider->define($data);
                $method->save();
            });
    }
}

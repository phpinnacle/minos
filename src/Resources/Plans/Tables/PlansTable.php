<?php

namespace PHPinnacle\Minos\Resources\Plans\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use PHPinnacle\Common\Filters\ActiveFilter;
use PHPinnacle\Common\Tables\ActiveColumn;
use PHPinnacle\Common\Tables\CreatedColumn;
use PHPinnacle\Common\Tables\DefaultColumn;
use PHPinnacle\Common\Tables\UpdatedColumn;
use PHPinnacle\Minos\Models\PaymentPlan;
use PHPinnacle\Minos\Resources\Plans\PlanResource;
use PHPinnacle\Tempo\Filters\DateRangeFilter;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->heading(__('phpinnacle-minos::resources.payment_plan.pages.list'))
            ->emptyStateIcon(PlanResource::getNavigationIcon())
            ->emptyStateHeading(__('phpinnacle-minos::resources.payment_plan.empty.heading'))
            ->emptyStateDescription(__('phpinnacle-minos::resources.payment_plan.empty.description'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('phpinnacle-minos::resources.payment_plan.fields.name'))
                    ->searchable(),
                TextColumn::make('parts')
                    ->label(__('phpinnacle-minos::resources.payment_plan.fields.parts'))
                    ->getStateUsing(self::formatParts(...)),
                DefaultColumn::make()
                    ->action(fn (PaymentPlan $record) => $record->toggleDefault()),
                ActiveColumn::make()
                    ->action(fn (PaymentPlan $record) => $record->toggleActive()),
                CreatedColumn::make(),
                UpdatedColumn::make(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('phpinnacle-minos::resources.payment_plan.actions.create')),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label(__('phpinnacle-minos::resources.payment_plan.actions.delete')),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('phpinnacle-minos::resources.payment_plan.actions.update'))
                    ->iconButton(),
                DeleteAction::make()
                    ->label(__('phpinnacle-minos::resources.payment_plan.actions.delete'))
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

    private static function formatParts(PaymentPlan $plan): ?string
    {
        if ($plan->parts === []) {
            return null;
        }

        return implode(', ', array_map(function (array $part) {
            ['value' => $value, 'delay' => $delay] = $part;

            $delay = (int) $delay;

            if ($delay === 0) {
                return $value . '%';
            }

            $days = trans_choice('phpinnacle-minos::resources.payment_plan.columns.delay', $delay, [
                'days' => $delay,
            ]);

            return sprintf('%s%% (%s)', $value, $days);
        }, $plan->parts));
    }
}

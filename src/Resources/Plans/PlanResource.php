<?php

namespace PHPinnacle\Minos\Resources\Plans;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use PHPinnacle\Minos\Models\PaymentPlan;

class PlanResource extends Resource
{
    protected static ?string $model = PaymentPlan::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return Schemas\PlanForm::configure($schema);
    }

    public static function getNavigationGroup(): string
    {
        return __('phpinnacle-minos::resources.payment_plan.group');
    }

    public static function getNavigationIcon(): ?string
    {
        return config('phpinnacle-minos.navigation.payment_plan.icon');
    }

    public static function getNavigationLabel(): string
    {
        return __('phpinnacle-minos::resources.payment_plan.label');
    }

    public static function getNavigationSort(): ?int
    {
        return config('phpinnacle-minos.navigation.payment_plan.sort');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }

    public static function table(Table $table): Table
    {
        return Tables\PlansTable::configure($table);
    }
}

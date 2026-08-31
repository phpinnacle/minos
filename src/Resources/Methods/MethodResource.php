<?php

namespace PHPinnacle\Minos\Resources\Methods;

use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use PHPinnacle\Minos\Models\PaymentMethod;

class MethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return Schemas\MethodForm::configure($schema);
    }

    public static function getNavigationGroup(): string
    {
        return __('phpinnacle-minos::resources.payment_method.group');
    }

    public static function getNavigationIcon(): ?string
    {
        return config('phpinnacle-minos.navigation.payment_method.icon');
    }

    public static function getNavigationLabel(): string
    {
        return __('phpinnacle-minos::resources.payment_method.label');
    }

    public static function getNavigationSort(): ?int
    {
        return config('phpinnacle-minos.navigation.payment_method.sort');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMethods::route('/'),
            'edit' => Pages\EditMethod::route('/{record}/edit'),
        ];
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'payments';
    }

    public static function table(Table $table): Table
    {
        return Tables\MethodsTable::configure($table);
    }
}

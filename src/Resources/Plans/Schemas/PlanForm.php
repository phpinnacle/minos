<?php

namespace PHPinnacle\Minos\Resources\Plans\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use PHPinnacle\Common\Forms\ActiveSelect;
use PHPinnacle\Minos\Models\PaymentPlan;
use PHPinnacle\Minos\Rules\PaymentParts;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->heading(__('phpinnacle-minos::resources.payment_plan.sections.general'))
                    ->columns(4)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('phpinnacle-minos::resources.payment_plan.fields.name'))
                            ->columnSpan(3)
                            ->minLength(1)
                            ->maxLength(255)
                            ->required(),
                        ActiveSelect::make()
                            ->disabled(fn (?PaymentPlan $record) => $record && $record->is_default),
                        Repeater::make('parts')
                            ->label(__('phpinnacle-minos::resources.payment_plan.fields.parts'))
                            ->addActionLabel(__('phpinnacle-minos::resources.payment_plan.actions.add_part'))
                            ->addActionAlignment(Alignment::Left)
                            ->columnSpanFull()
                            ->minItems(1)
                            ->compact()
                            ->default([
                                ['value' => 100, 'delay' => 0],
                            ])
                            ->table([
                                Repeater\TableColumn::make(__(
                                    'phpinnacle-minos::resources.payment_plan.fields.part_value',
                                ))
                                    ->width('80%'),
                                Repeater\TableColumn::make(__(
                                    'phpinnacle-minos::resources.payment_plan.fields.part_delay',
                                ))
                                    ->width('20%'),
                            ])
                            ->schema([
                                TextInput::make('value')
                                    ->integer()
                                    ->minValue(1)
                                    ->maxValue(100)
                                    ->required(),
                                TextInput::make('delay')
                                    ->integer()
                                    ->minValue(0)
                                    ->maxValue(365)
                                    ->required(),
                            ])
                            ->rules([
                                new PaymentParts,
                            ]),
                    ]),
            ]);
    }
}

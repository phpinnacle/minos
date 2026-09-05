<?php

namespace PHPinnacle\Minos\Forms;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Support\Enums\Alignment;
use PHPinnacle\Minos\Rules;
use PHPinnacle\Money\Forms\MoneyInput;
use PHPinnacle\Money\Money;
use PHPinnacle\Tempo\Forms\DatePicker;

class PlanRepeater extends Repeater
{
    private Closure|Money|null $expectedAmount = null;

    public static function getDefaultName(): string
    {
        return 'payments';
    }

    public function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('phpinnacle-minos::forms.plan_repeater.label'))
            ->addActionAlignment(Alignment::Left)
            ->addAction(function (Action $action) {
                $action
                    ->label(__('phpinnacle-minos::forms.plan_repeater.actions.add'))
                    ->icon('phosphor-plus');
            })
            ->deleteAction(function (Action $action) {
                $action->disabled(fn (PlanRepeater $component) => $component->getItemsCount() === 1);
            })
            ->table([
                Repeater\TableColumn::make(__('phpinnacle-minos::forms.plan_repeater.fields.amount'))
                    ->markAsRequired(),
                Repeater\TableColumn::make(__('phpinnacle-minos::forms.plan_repeater.fields.date'))
                    ->markAsRequired(),
            ])
            ->schema(fn (PlanRepeater $component) => [
                MoneyInput::make('amount')
                    ->default($component->leftAmount())
                    ->table(),
                DatePicker::make('sale_at')
                    ->default($component->lastDate())
                    ->table(),
            ])
            ->compact()
            ->minItems(1)
            ->defaultItems(1)
            ->reorderable(false)
            ->rules(fn (PlanRepeater $component) => [
                new Rules\PaymentScheme($component->evaluate($this->expectedAmount), dateField: 'sale_at'),
            ]);
    }

    public function expectedAmount(Closure|Money $amount): self
    {
        $this->expectedAmount = $amount;

        return $this;
    }

    private function leftAmount(): ?Money
    {
        $expected = $this->evaluate($this->expectedAmount);
        $amounts = array_filter(array_column($this->getState(), 'amount'));

        return $amounts !== [] ? $expected?->sub(Money::sum(...$amounts)) : $expected;
    }

    private function lastDate(): DateTimeInterface
    {
        $dates = array_filter(array_column($this->getState(), 'sale_at'));
        $dates = array_map(fn ($date) => CarbonImmutable::parse($date)->endOfDay(), $dates);

        return $dates !== [] ? max($dates)->addDays(1) : CarbonImmutable::now()->endOfDay()->addDay();
    }
}

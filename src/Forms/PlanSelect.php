<?php

namespace PHPinnacle\Minos\Forms;

use Filament\Forms\Components\Select;
use PHPinnacle\Minos\Models\PaymentPlan;
use PHPinnacle\Minos\Resources\Plans\PlanResource;

class PlanSelect extends Select
{
    private bool $withCustom = false;

    public static function getDefaultName(): string
    {
        return 'plan_id';
    }

    public function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('phpinnacle-minos::forms.payment_plan.label'))
            ->placeholder(__('phpinnacle-minos::forms.payment_plan.placeholder'))
            ->prefixIcon(PlanResource::getNavigationIcon())
            ->selectablePlaceholder(false)
            ->options(function () {
                $plans = PaymentPlan::list();

                if ($this->withCustom) {
                    $plans = $plans->prepend(__('phpinnacle-minos::forms.payment_plan.custom'), PaymentPlan::CUSTOM_ID);
                }

                return $plans;
            });
    }

    public function withCustom(bool $value = true): self
    {
        $this->withCustom = $value;

        return $this;
    }

    public function withDefault(bool $value = true): self
    {
        return $this->default($value ? PaymentPlan::default() : null);
    }
}

<?php

namespace PHPinnacle\Minos\Resources\Plans\Pages;

use Filament\Resources\Pages\CreateRecord;
use PHPinnacle\Minos\Resources\Plans\PlanResource;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    public function getTitle(): string
    {
        return __('phpinnacle-minos::resources.payment_plan.pages.create');
    }
}

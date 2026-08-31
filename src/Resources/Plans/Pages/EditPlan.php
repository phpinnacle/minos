<?php

namespace PHPinnacle\Minos\Resources\Plans\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use PHPinnacle\Minos\Resources\Plans\PlanResource;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    public function getTitle(): string
    {
        return __('phpinnacle-minos::resources.payment_plan.pages.edit');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label(__('phpinnacle-minos::resources.payment_plan.actions.delete')),
        ];
    }
}

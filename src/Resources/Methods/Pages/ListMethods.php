<?php

namespace PHPinnacle\Minos\Resources\Methods\Pages;

use Filament\Resources\Pages\ListRecords;
use PHPinnacle\Minos\Resources\Methods\MethodResource;

class ListMethods extends ListRecords
{
    protected static string $resource = MethodResource::class;

    public function getTitle(): string
    {
        return '';
    }
}

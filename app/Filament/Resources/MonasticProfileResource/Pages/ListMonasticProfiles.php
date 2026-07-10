<?php

namespace App\Filament\Resources\MonasticProfileResource\Pages;

use App\Filament\Resources\MonasticProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMonasticProfiles extends ListRecords
{
    protected static string $resource = MonasticProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

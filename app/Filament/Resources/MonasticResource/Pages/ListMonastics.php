<?php

namespace App\Filament\Resources\MonasticResource\Pages;

use App\Filament\Resources\MonasticResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMonastics extends ListRecords
{
    protected static string $resource = MonasticResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

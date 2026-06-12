<?php

namespace App\Filament\Resources\TempleResource\Pages;

use App\Filament\Resources\TempleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTemples extends ListRecords
{
    protected static string $resource = TempleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

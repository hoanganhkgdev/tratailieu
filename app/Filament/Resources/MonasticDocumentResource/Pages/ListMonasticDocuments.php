<?php

namespace App\Filament\Resources\MonasticDocumentResource\Pages;

use App\Filament\Resources\MonasticDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMonasticDocuments extends ListRecords
{
    protected static string $resource = MonasticDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

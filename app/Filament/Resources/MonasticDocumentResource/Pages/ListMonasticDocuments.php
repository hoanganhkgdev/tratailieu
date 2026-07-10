<?php

namespace App\Filament\Resources\MonasticDocumentResource\Pages;

use App\Filament\Resources\MonasticDocumentResource;
use App\Filament\Widgets\MonasticDocumentStatsOverview;
use Filament\Resources\Pages\ListRecords;

class ListMonasticDocuments extends ListRecords
{
    protected static string $resource = MonasticDocumentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            MonasticDocumentStatsOverview::class,
        ];
    }
}

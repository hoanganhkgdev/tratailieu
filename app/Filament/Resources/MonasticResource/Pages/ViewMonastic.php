<?php

namespace App\Filament\Resources\MonasticResource\Pages;

use App\Filament\Resources\MonasticResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewMonastic extends ViewRecord
{
    protected static string $resource = MonasticResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('Chỉnh sửa'),
        ];
    }
}

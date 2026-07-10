<?php

namespace App\Filament\Resources\MonasticProfileResource\Pages;

use App\Filament\Resources\MonasticProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewMonasticProfile extends ViewRecord
{
    protected static string $resource = MonasticProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}

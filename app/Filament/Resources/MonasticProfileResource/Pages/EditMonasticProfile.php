<?php

namespace App\Filament\Resources\MonasticProfileResource\Pages;

use App\Filament\Resources\MonasticProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMonasticProfile extends EditRecord
{
    protected static string $resource = MonasticProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}

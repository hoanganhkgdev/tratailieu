<?php

namespace App\Filament\Resources\MonasticResource\Pages;

use App\Filament\Resources\MonasticResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMonastic extends EditRecord
{
    protected static string $resource = MonasticResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

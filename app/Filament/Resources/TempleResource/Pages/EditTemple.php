<?php

namespace App\Filament\Resources\TempleResource\Pages;

use App\Filament\Resources\TempleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTemple extends EditRecord
{
    protected static string $resource = TempleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}

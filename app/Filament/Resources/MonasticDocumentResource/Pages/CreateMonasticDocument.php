<?php

namespace App\Filament\Resources\MonasticDocumentResource\Pages;

use App\Filament\Resources\MonasticDocumentResource;
use App\Jobs\ProcessDocumentJob;
use Filament\Resources\Pages\CreateRecord;

class CreateMonasticDocument extends CreateRecord
{
    protected static string $resource = MonasticDocumentResource::class;

    protected function afterCreate(): void
    {
        ProcessDocumentJob::dispatch($this->record);
    }
}

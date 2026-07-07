<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\TempleImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTempleDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Document $document) {}

    public function handle(TempleImportService $importer): void
    {
        $importer->process($this->document);
    }
}

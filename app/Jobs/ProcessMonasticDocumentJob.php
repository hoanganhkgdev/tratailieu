<?php

namespace App\Jobs;

use App\Models\MonasticDocument;
use App\Services\MonasticImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMonasticDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public MonasticDocument $document) {}

    public function handle(MonasticImportService $importer): void
    {
        $importer->process($this->document);
    }
}

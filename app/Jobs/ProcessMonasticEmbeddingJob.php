<?php

namespace App\Jobs;

use App\Models\Monastic;
use App\Services\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMonasticEmbeddingJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries   = 2;

    public function __construct(public Monastic $monastic) {}

    public function handle(EmbeddingService $embedding): void
    {
        $text = $this->monastic->toSearchableText();

        if (empty(trim($text))) {
            return;
        }

        $vector = $embedding->embed($text);

        $this->monastic->updateQuietly(['embedding' => $vector]);
    }
}

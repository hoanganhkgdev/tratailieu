<?php

namespace App\Jobs;

use App\Services\MonasticImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AnalyzeAndImportMonasticJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries   = 2;

    public function __construct(
        private string $filePath,
        private int    $provinceId,
        private string $fileType,
    ) {}

    public function handle(MonasticImportService $service): void
    {
        $disk = Storage::disk('public');
        $fileName = basename($this->filePath);

        if (! $disk->exists($this->filePath)) {
            Log::warning("Monastic Import: file không tồn tại - {$this->filePath}");
            return;
        }

        try {
            $data = $service->analyze($this->filePath, $this->fileType);

            $data['province_id'] = $data['province_id'] ?? $this->provinceId;
            $data['_file_path'] = $this->filePath;
            $data['_file_name'] = $fileName;
            $data['_file_type'] = $this->fileType;

            $monastic = $service->import($data);

            ProcessMonasticEmbeddingJob::dispatch($monastic);

            Log::info("Monastic Import OK: {$fileName} → {$monastic->full_name}");
        } catch (\Throwable $e) {
            Log::error("Monastic Import FAIL: {$fileName} — {$e->getMessage()}");
        }
    }
}

<?php

namespace App\Jobs;

use App\Services\MonasticImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class AnalyzeMonasticFileJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;
    public int $tries   = 1;

    public function __construct(
        private string $batchId,
        private int    $index,
        private string $filePath,
        private string $fileType,
    ) {}

    public function handle(MonasticImportService $service): void
    {
        try {
            if (! Storage::disk('public')->exists($this->filePath)) {
                throw new \RuntimeException("Không tìm thấy file: {$this->filePath}");
            }

            $data   = $service->analyze($this->filePath, $this->fileType);
            $result = array_merge($data, [
                '_file_path' => $this->filePath,
                '_file_name' => basename($this->filePath),
                '_file_type' => $this->fileType,
                '_error'     => null,
            ]);
        } catch (\Throwable $e) {
            $result = [
                '_file_path'       => $this->filePath,
                '_file_name'       => basename($this->filePath),
                '_file_type'       => $this->fileType,
                '_error'           => $e->getMessage(),
                'full_name'        => null,
                'religious_name'   => null,
                'gender'           => 'nam',
                'temple_name'      => null,
                'temple_id'        => null,
                'province_name'    => null,
                'province_id'      => null,
                'rank'             => null,
                'current_position' => null,
                'phone'            => null,
                'classifications'  => [],
                'activities'       => [],
            ];
        }

        Cache::put("monastic_analysis_{$this->batchId}_{$this->index}", $result, now()->addHours(2));
        Cache::increment("monastic_analysis_{$this->batchId}_done");
    }
}

<?php

namespace App\Jobs;

use App\Models\Province;
use App\Services\SmartImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportTempleFileJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;
    public int $tries   = 2;

    public function __construct(
        private string $filePath,
        private int    $provinceId,
    ) {}

    public function handle(SmartImportService $service): void
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($this->filePath)) {
            Log::warning("Import: file không tồn tại - {$this->filePath}");
            return;
        }

        $fileName = basename($this->filePath);
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (! in_array($ext, ['pdf', 'docx'])) {
            Log::warning("Import: bỏ qua file không hỗ trợ - {$fileName}");
            return;
        }

        try {
            $data = $service->analyze($this->filePath, $ext);
            $data['province_id'] = $data['province_id'] ?? $this->provinceId;

            $newPath = 'documents/' . $fileName;
            if ($disk->exists($newPath)) {
                $newPath = 'documents/' . pathinfo($fileName, PATHINFO_FILENAME) . '_' . uniqid() . '.' . $ext;
            }
            $disk->move($this->filePath, $newPath);

            $document = $service->import($newPath, $fileName, $ext, $data);
            ProcessDocumentJob::dispatch($document);

            Log::info("Import OK: {$fileName} → {$document->title}");
        } catch (\Throwable $e) {
            Log::error("Import FAIL: {$fileName} — {$e->getMessage()}");
        }
    }
}

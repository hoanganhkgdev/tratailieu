<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Province;
use App\Services\SmartImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportTempleFileJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries   = 1;

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

            $province = Province::find($data['province_id']);
            $provinceSlug = $province ? \Illuminate\Support\Str::slug($province->name) : 'chua-xac-dinh';

            // Nếu file đã nằm trong tu-vien/ thì dùng ngay, không cần move
            if (str_starts_with($this->filePath, 'tu-vien/')) {
                $newPath = $this->filePath;
                // Tránh import trùng khi reimport-all dispatch file đã có document
                if (Document::where('file_path', $newPath)->exists()) {
                    Log::info("Import: bỏ qua (đã import rồi) - {$fileName}");
                    return;
                }
            } else {
                $newPath = "tu-vien/{$provinceSlug}/" . pathinfo($fileName, PATHINFO_FILENAME) . '_' . uniqid() . '.' . $ext;
                $disk->move($this->filePath, $newPath);
            }

            $document = $service->import($newPath, $fileName, $ext, $data);
            ProcessDocumentJob::dispatch($document);

            Log::info("Import OK: {$fileName} → {$document->title}");
        } catch (\Throwable $e) {
            Log::error("Import FAIL: {$fileName} — {$e->getMessage()}");
        }
    }
}

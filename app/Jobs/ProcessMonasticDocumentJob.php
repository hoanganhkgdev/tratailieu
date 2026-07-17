<?php

namespace App\Jobs;

use App\Models\MonasticDocument;
use App\Services\MonasticImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMonasticDocumentJob implements ShouldQueue
{
    use Queueable;

    /**
     * MonasticImportService::process() chỉ bắt PermanentImportException (lỗi dữ liệu/
     * định dạng thật, thử lại vô ích) — mọi lỗi khác (Gemini quá tải, quota, JSON cắt
     * cụt...) cố ý bay ra tới đây để Laravel tự retry, thay vì phải có người vào tay
     * requeue từng file như trước.
     */
    public int $tries = 5;

    public function __construct(public MonasticDocument $document) {}

    /**
     * Giãn cách tăng dần (1 → 3 → 5 → 10 phút) — "high demand" của Gemini thường chỉ
     * kéo dài vài phút, đợi lâu hơn giữa các lần thử tăng khả năng qua được thay vì
     * dội liên tục vào lúc đang quá tải.
     */
    public function backoff(): array
    {
        return [60, 180, 300, 600];
    }

    public function handle(MonasticImportService $importer): void
    {
        $importer->process($this->document);
    }

    public function failed(\Throwable $exception): void
    {
        // Đã thử hết 5 lần (~19 phút) vẫn lỗi — lúc này mới thật sự đánh dấu failed để
        // người dùng biết mà xử lý tay (thường không tới bước này).
        $this->document->update([
            'status'        => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Province;
use App\Models\Temple;
use Gemini\Laravel\Facades\Gemini;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SmartImportService
{
    public function __construct(private DocumentParserService $parser) {}

    public function analyze(string $filePath, string $fileType): array
    {
        $text    = $this->parser->extractText($filePath, $fileType);
        $excerpt = Str::limit($text, 3000);

        $prompt = <<<PROMPT
Hãy phân tích đoạn văn bản sau trích từ một tài liệu Phật giáo Việt Nam và trả về thông tin dưới dạng JSON.

Văn bản:
{$excerpt}

Hãy trả về JSON với các trường sau (nếu không tìm được thì để null):
{
  "temple_name": "tên chùa hoặc tự viện",
  "temple_type": "chua | tu_vien | tinh_xa | thien_vien | tinh_that",
  "province_name": "tên tỉnh hoặc thành phố (ví dụ: Hà Nội, TP. Hồ Chí Minh)",
  "region": "Miền Bắc | Miền Trung | Miền Nam",
  "head_monk": "tên trụ trì nếu có",
  "address": "địa chỉ nếu có",
  "document_title": "tiêu đề phù hợp cho tài liệu này",
  "document_description": "mô tả ngắn 1-2 câu về nội dung tài liệu"
}

Chỉ trả về JSON thuần, không giải thích thêm.
PROMPT;

        $response = Gemini::generativeModel("gemini-2.5-flash")->generateContent($prompt);
        $raw      = $response->text();

        $raw = preg_replace('/```json|```/i', '', $raw);

        $data = json_decode(trim($raw), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $data = $this->fallback($filePath);
        }

        // Đối chiếu tên tỉnh AI nhận diện với danh sách 34 tỉnh chính thức (kể cả tên cũ
        // trước sáp nhập qua aliases). KHÔNG tự tạo tỉnh mới — nếu không khớp được
        // (vd: tài liệu ghi địa danh cũ, hoặc AI nhầm tên huyện/xã thành tên tỉnh),
        // để trống province_id và yêu cầu người dùng tự chọn ở bước xem trước.
        $matched = Province::findByNameOrAlias($data['province_name'] ?? null);

        $data['province_id'] = $matched?->id;

        return $data;
    }

    public function import(string $filePath, string $fileName, string $fileType, array $data): Document
    {
        // Tỉnh/thành phải khớp với danh sách 34 tỉnh chính thức — không tự tạo mới.
        // province_id được set sẵn từ analyze() (nếu khớp tự động) hoặc do người dùng
        // chọn thủ công ở bước xem trước khi AI không nhận diện được.
        $province = Province::find($data['province_id'] ?? null);

        if (! $province) {
            throw new \RuntimeException('Chưa xác định được tỉnh/thành phù hợp. Vui lòng chọn tỉnh ở bước xem trước.');
        }

        $templeName = $data['temple_name'] ?? 'Chưa xác định';
        $temple = Temple::firstOrCreate(
            ['slug' => Str::slug($templeName)],
            [
                'province_id'   => $province->id,
                'name'          => $templeName,
                'type'          => $data['temple_type'] ?? 'chua',
                'address'       => $data['address'] ?? null,
                'head_monk'     => $data['head_monk'] ?? null,
                'is_active'     => true,
            ]
        );

        $fileSize = filesize($this->parser->resolvePath($filePath)) ?: 0;

        return Document::create([
            'temple_id'   => $temple->id,
            'uploaded_by' => Auth::id(),
            'title'       => $data['document_title'] ?? $fileName,
            'description' => $data['document_description'] ?? null,
            'file_path'   => $filePath,
            'file_name'   => $fileName,
            'file_type'   => $fileType,
            'file_size'   => $fileSize,
            'status'      => 'pending',
        ]);
    }

    private function fallback(string $filePath): array
    {
        return [
            'temple_name'          => null,
            'temple_type'          => 'chua',
            'province_name'        => null,
            'region'               => null,
            'head_monk'            => null,
            'address'              => null,
            'document_title'       => basename($filePath),
            'document_description' => null,
        ];
    }
}

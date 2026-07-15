<?php

namespace App\Services;

use App\Models\MonasticDocument;
use App\Models\MonasticProfile;
use Gemini\Data\Blob;
use Gemini\Data\GenerationConfig;
use Gemini\Data\ThinkingConfig;
use Gemini\Enums\MimeType as GeminiMimeType;
use Gemini\Enums\ResponseMimeType;
use Gemini\Laravel\Facades\Gemini;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Phiếu số 3" là mẫu chuẩn hóa nhà nước, nhãn từng field cố định tuyệt đối (đã kiểm
 * chứng qua nhiều file khác nhau) — file có lớp text thật (docx/PDF gõ máy) đọc thẳng
 * bằng regex (MonasticFormParserService), MIỄN PHÍ và đáng tin hơn AI (không
 * random/không bị cắt JSON giữa chừng).
 *
 * File scan/chụp ảnh (không có lớp text) thì regex bó tay — bắt buộc cần AI đọc ảnh
 * (vision). Dùng Gemini ở chế độ FREE TIER (key tạo trên project KHÔNG bật billing,
 * xem README/nhật ký trao đổi) — cùng độ chính xác đã kiểm chứng trước đó, chỉ khác
 * bị giới hạn tốc độ RẤT THẤP (đo thực tế CHỈ 5 request/phút cho model
 * gemini-3.5-flash, không phải ~15 như ước tính ban đầu) thay vì tính tiền theo dùng
 * thật — xem throttleGeminiCall() để biết cách ép giãn cách giữa các lần gọi.
 *
 * KHÔNG còn field "tự viện" và KHÔNG tự đoán tỉnh qua AI nữa — tỉnh do người dùng CHỌN
 * TRƯỚC lúc upload (đã lưu sẵn trên $document->province_id), xem finalize().
 */
class MonasticImportService
{
    public function __construct(
        private DocumentParserService $parser,
        private MonasticFormParserService $formParser,
    ) {}

    /**
     * Dưới ngưỡng này coi như PDF không có lớp text thật (chỉ là ảnh scan/chụp trang
     * giấy nhúng vào PDF) — đo thực tế: PDF scan cho ra chuỗi rỗng hoặc vài ký tự
     * rác, trong khi PDF có lớp text luôn cho ra hàng nghìn ký tự.
     */
    private const MIN_TEXT_LENGTH_FOR_TEXT_MODE = 200;

    /**
     * Gemini thỉnh thoảng trả JSON bị cắt cụt giữa chừng dù finishReason báo "STOP"
     * (đã kiểm chứng thực tế trước đây: cùng 1 ảnh, gọi lại nhiều lần, có lần JSON
     * hợp lệ có lần cụt ở cùng vị trí ngẫu nhiên, ~1/3 số lần) — thử lại vài lần thay
     * vì để cả hồ sơ rơi vào "failed" oan. Free tier còn bị giới hạn tốc độ (429) —
     * cùng cơ chế retry này xử lý luôn, xem generateWithRetry().
     */
    private const MAX_JSON_RETRIES = 3;

    public function process(MonasticDocument $document): void
    {
        $data = null;

        try {
            $document->update(['status' => 'processing']);

            if ($document->file_type === 'pdf') {
                $text = $this->parser->extractText($document->file_path, $document->file_type);
            } else {
                // Phiếu tăng ni có nhiều field checkbox ☒/☐ (Phân loại, Tình trạng hiện
                // tại, Phạm vi hoạt động) — dùng extractTextPreservingCheckboxes() thay
                // vì extractText() thường, xem lý do trong DocumentParserService.
                $text = $this->parser->extractTextPreservingCheckboxes($document->file_path, $document->file_type);
            }

            // Ngoài "quá ngắn" (file scan/chụp ảnh), 1 số PDF có lớp text nhưng bị lỗi
            // encoding font nhúng (đã gặp thực tế: pdfparser giải mã ra byte KHÔNG hợp
            // lệ UTF-8 — không phải thiếu ký tự mà đọc sai hẳn font map) — mb_check_encoding
            // bắt được, coi như không có text dùng được, chuyển sang vision.
            $textUsable = mb_strlen(trim($text)) >= self::MIN_TEXT_LENGTH_FOR_TEXT_MODE && mb_check_encoding($text, 'UTF-8');

            if ($document->file_type === 'pdf' && ! $textUsable) {
                // File scan/chụp ảnh trang giấy — không còn cách nào khác ngoài AI đọc
                // ảnh trực tiếp (vision), regex không có gì để bám vào.
                $data = $this->processScannedPdf($document);
            } else {
                $clarified = $this->clarifyCheckboxes($text);
                $data = $this->formParser->parse($clarified);

                if ($data === null) {
                    throw new \RuntimeException('Không nhận diện được đúng mẫu "Phiếu số 3" (nhãn field không khớp) — kiểm tra lại định dạng file hoặc nhập tay.');
                }
            }

            $this->finalize($document, $data);
        } catch (\Throwable $e) {
            $document->update([
                'status'         => 'failed',
                'error_message'  => $e->getMessage(),
                'extracted_json' => $data,
            ]);
        }
    }

    private function processScannedPdf(MonasticDocument $document): array
    {
        $images = $this->parser->extractPageImages($document->file_path);

        if (empty($images)) {
            throw new \RuntimeException('File PDF không có lớp text và cũng không trích được ảnh trang nào để đọc bằng AI.');
        }

        return $this->analyzeFromImages($document, $images);
    }

    private function finalize(MonasticDocument $document, array $data): void
    {
        // KHÔNG tự đoán tỉnh từ địa chỉ trong nội dung phiếu nữa — dò chuỗi con dễ
        // trùng nhầm địa danh (xem MonasticFormParserService). Tỉnh giờ do người dùng
        // CHỌN TRƯỚC lúc upload (trang Nhập hồ sơ tăng ni / lệnh tang-ni:bulk-import),
        // đã lưu sẵn trên $document->province_id — chỉ cần chép sang profile, không
        // cần suy luận gì thêm. Không còn field "tự viện" (temple_id) — bỏ hẳn theo
        // yêu cầu, không có gì tự động gán nữa.

        // Mỗi document ứng với đúng 1 hồ sơ — updateOrCreate theo monastic_document_id
        // để bấm "Xử lý lại" cập nhật đúng bản ghi cũ thay vì tạo hồ sơ trùng lặp.
        //
        // truncate() theo ĐÚNG giới hạn cột string tương ứng cho MỌI field (không chỉ
        // id_number/phone như trước) — đã gặp thực tế 1 file định dạng lạ (lẫn ký tự
        // "●") làm regex xác định sai ranh giới field, khiến "gender" (cột string(20))
        // nuốt luôn nội dung của nhiều field khác, dài tới mức MySQL từ chối insert
        // (lỗi "Data too long"). Field text (current_position, notes...) không giới
        // hạn thực tế nên không cần truncate.
        MonasticProfile::updateOrCreate(
            ['monastic_document_id' => $document->id],
            [
                'province_id'                => $document->province_id,
                'full_name'                  => $this->truncate($data['full_name'] ?? null, 255) ?? 'Chưa xác định',
                'religious_name'             => $this->truncate($data['religious_name'] ?? null, 255),
                'birth_date'                 => $this->toNullableDate($data['birth_date'] ?? null),
                'gender'                     => $this->truncate($data['gender'] ?? null, 20),
                'ethnicity'                  => $this->truncate($data['ethnicity'] ?? null, 100),
                'nationality'                => $this->truncate($data['nationality'] ?? null, 100),
                'id_number'                  => $this->truncate($data['id_number'] ?? null, 30),
                'id_issued_date'             => $this->toNullableDate($data['id_issued_date'] ?? null),
                'id_issued_place'            => $this->truncate($data['id_issued_place'] ?? null, 255),
                'hometown'                   => $this->truncate($data['hometown'] ?? null, 255),
                'permanent_address'          => $this->truncate($data['permanent_address'] ?? null, 255),
                'current_address'            => $this->truncate($data['current_address'] ?? null, 255),
                'religion'                   => $this->truncate($data['religion'] ?? null, 100),
                'religious_org'              => $this->truncate($data['religious_org'] ?? null, 255),
                'sect'                       => $this->truncate($data['sect'] ?? null, 255),
                'classification'             => $this->toClassificationArray($data['classification'] ?? null),
                'current_position'           => $data['current_position'] ?? null,
                'ordination_date'            => $this->toNullableDate($data['ordination_date'] ?? null),
                'concurrent_position'        => $data['concurrent_position'] ?? null,
                'activity_scope'             => $this->truncate($data['activity_scope'] ?? null, 255),
                'notes'                      => $data['notes'] ?? null,
                'education_level'            => $this->truncate($data['education_level'] ?? null, 255),
                'professional_qualification' => $this->truncate($data['professional_qualification'] ?? null, 255),
                'religious_education_level'  => $this->truncate($data['religious_education_level'] ?? null, 255),
                'training_institutions'      => $data['training_institutions'] ?? null,
                'languages'                  => $this->truncate($data['languages'] ?? null, 255),
                'activity_history'           => $data['activity_history'] ?? null,
                'commendation_discipline'    => $data['commendation_discipline'] ?? null,
                'violations'                 => $data['violations'] ?? null,
                'congress_term'              => $this->truncate($data['congress_term'] ?? null, 255),
                'phone'                      => $this->truncate($data['phone'] ?? null, 30),
                'email'                      => $this->truncate($data['email'] ?? null, 255),
                'status'                     => $this->truncate($data['status'] ?? null, 255),
            ]
        );

        $document->update([
            'status'         => 'ready',
            'processed_at'   => now(),
            'error_message'  => null,
            'extracted_json' => $data,
        ]);
    }

    private function truncate(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr(trim($value), 0, $length);
    }

    /**
     * MonasticFormParserService dựa vào 2 nhãn chữ này để biết checkbox nào được chọn
     * (xem selectedOptions() ở đó) — thay ☒/☐ bằng nhãn chữ tường minh, dễ so khớp
     * bằng chuỗi hơn hẳn so với phân biệt hình dạng ký tự Unicode.
     */
    private function clarifyCheckboxes(string $text): string
    {
        return str_replace(
            ['☒', '☐'],
            [' [ĐÃ_CHỌN] ', ' [chưa_chọn] '],
            $text
        );
    }

    /**
     * Ngày dạng "dd/mm/yyyy" (đúng định dạng phiếu gốc) hoặc chuỗi rỗng/"..." khi
     * phiếu bỏ trống field đó — không phải date hợp lệ thì trả null thay vì để lỗi DB
     * chặn cả hồ sơ (cùng nguyên tắc với toNullableInt() ở TempleImportService).
     */
    private function toNullableDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::createFromFormat('d/m/Y', trim($value))->toDateString();
        } catch (\Throwable) {
            try {
                return \Illuminate\Support\Carbon::parse(trim($value))->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }
    }

    /**
     * @return array<int, string>|null
     */
    private function toClassificationArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $valid = ['chuc_sac', 'chuc_viec', 'nha_tu_hanh'];
        $filtered = array_values(array_intersect($value, $valid));

        return $filtered === [] ? null : $filtered;
    }

    /**
     * Dùng cho file scan/chụp ảnh (không có lớp text) — CÙNG schema field với
     * MonasticFormParserService (không có province_name/temple_name — tỉnh đã chọn
     * sẵn lúc upload, không còn field tự viện — xem finalize()), chỉ khác input là
     * ẢNH từng trang chứ không phải văn bản.
     */
    private const VISION_INSTRUCTIONS = <<<PROMPT
Các ảnh đính kèm là từng trang chụp/scan của "Phiếu thu thập thông tin dữ liệu về chức sắc, chức
việc, nhà tu hành tôn giáo (Phiếu số 3)" — hồ sơ CÁ NHÂN của 1 tăng/ni Phật giáo Việt Nam, xếp theo
đúng thứ tự trang. Đọc trực tiếp nội dung viết tay hoặc đánh máy trong ảnh và trả về JSON.

Phiếu có các nhóm thông tin theo thứ tự: (I) Định danh & cá nhân cơ bản, (II) Hành đạo & chuyên
môn tôn giáo, (III) Đào tạo, (IV) Hoạt động & bổ nhiệm, (V) Liên hệ & tình trạng. Lưu ý:
- Chữ viết tay có thể khó đọc — cố gắng đọc chính xác nhất có thể, phần nào THẬT SỰ không đọc
  được thì trả null, TUYỆT ĐỐI không đoán bừa hay bịa ra giá trị.
- Field nào phiếu để trống, gạch chấm "...", hoặc không ghi gì thì trả null.
- Ngày tháng giữ nguyên định dạng "dd/mm/yyyy" như trong phiếu.
- "Phân loại" là checkbox có thể tick nhiều ô — nhìn trực tiếp trong ảnh xem ô vuông nào có dấu
  tick/gạch chéo/tô đậm (không phải ô trống), trả về mảng các giá trị đã tick trong số: "chuc_sac"
  (Chức sắc), "chuc_viec" (Chức việc), "nha_tu_hanh" (Nhà tu hành).
- "Tình trạng hiện tại" phiếu có 2 nhóm checkbox tuỳ đối tượng (chức sắc/chức việc: đang hoạt
  động/hưu trí/cách chức/đã chết; nhà tu hành: đang tu hành/hoàn tục/đã chết/tẩn xuất) — chỉ lấy
  đúng 1 giá trị có ô được tick thành chuỗi text, ví dụ "Đang hoạt động" hoặc "Đang tu hành".

Trả về JSON đúng định dạng sau (field nào phiếu không có/để trống/không đọc được thì null):
{
  "full_name": "họ và tên khai sinh",
  "religious_name": "tên trong tôn giáo / pháp danh",
  "birth_date": "dd/mm/yyyy",
  "gender": "Nam | Nữ",
  "ethnicity": "dân tộc",
  "nationality": "quốc tịch",
  "id_number": "số CCCD",
  "id_issued_date": "dd/mm/yyyy",
  "id_issued_place": "nơi cấp CCCD",
  "hometown": "quê quán",
  "permanent_address": "địa chỉ thường trú",
  "current_address": "nơi ở hiện tại (nguyên văn)",
  "religion": "tôn giáo",
  "religious_org": "tổ chức tôn giáo",
  "sect": "hệ phái/dòng tu",
  "classification": ["chuc_sac", "chuc_viec", "nha_tu_hanh"],
  "current_position": "chức vụ/phẩm vị hiện tại",
  "ordination_date": "dd/mm/yyyy",
  "concurrent_position": "chức vụ kiêm nhiệm",
  "activity_scope": "phạm vi hoạt động (toàn quốc / một số tỉnh, ghi rõ / tên tỉnh cụ thể)",
  "notes": "ghi chú",
  "education_level": "trình độ học vấn phổ thông",
  "professional_qualification": "trình độ chuyên môn",
  "religious_education_level": "trình độ tu học",
  "training_institutions": "cơ sở đào tạo tôn giáo đã theo học",
  "languages": "ngoại ngữ/tiếng dân tộc khác",
  "activity_history": "quá trình hoạt động: từ ngày-đến ngày, nơi hành đạo, chức vụ đảm nhận (gộp thành 1 đoạn văn ngắn)",
  "commendation_discipline": "khen thưởng/kỷ luật",
  "violations": "khiếu kiện, vi phạm pháp luật",
  "congress_term": "nhiệm kỳ đại hội từ năm-đến năm",
  "phone": "số điện thoại",
  "email": "email",
  "status": "tình trạng hiện tại (1 giá trị text, xem hướng dẫn trên)"
}

Chỉ trả về JSON thuần, không giải thích thêm, không bọc trong markdown code block.
PROMPT;

    /**
     * @param  array<int, array{data: string, mime: string}>  $images
     */
    private function analyzeFromImages(MonasticDocument $document, array $images): array
    {
        $content = [self::VISION_INSTRUCTIONS];

        foreach ($images as $image) {
            $content[] = new Blob(
                mimeType: GeminiMimeType::from($image['mime']),
                data: base64_encode($image['data'])
            );
        }

        return $this->generateWithRetry($document, fn () => Gemini::generativeModel(model: 'gemini-flash-latest')
            ->withGenerationConfig(new GenerationConfig(
                // BẮT BUỘC — nếu không set, model tự bật "thinking" ngầm (đã kiểm chứng
                // thực tế: model mặc định sinh ~1900 token "thoughts" ẩn dù không cần).
                // Free tier vẫn tính vào rate limit dù không tính tiền, khoá cứng để
                // tránh lãng phí quota vô ích — đã đo lại xác nhận không đổi độ chính xác.
                thinkingConfig: new ThinkingConfig(includeThoughts: false, thinkingBudget: 0),
                responseMimeType: ResponseMimeType::APPLICATION_JSON,
            ))
            ->generateContent($content));
    }

    /**
     * Quota free tier đo được thực tế CHỈ 5 request/phút — TÍNH CHUNG cho toàn bộ API
     * key, không phải riêng từng worker (đã gặp thực tế: 3 worker cùng lúc gọi AI làm
     * 76/546 file bị từ chối ngay "exceeded your current quota"). Ép giãn cách tối
     * thiểu giữa 2 lần gọi Gemini bất kỳ (dùng Cache — dùng chung được giữa nhiều
     * worker/process khác nhau) để KHÔNG BAO GIỜ vượt quota ngay từ đầu, thay vì cứ
     * gọi dồn dập rồi trông chờ retry vá lại.
     *
     * 60s / 5 request = 12s/request tối thiểu — dùng 13s để có biên an toàn.
     */
    private const MIN_SECONDS_BETWEEN_GEMINI_CALLS = 13;

    private function throttleGeminiCall(): void
    {
        Cache::lock('gemini-vision-throttle', 20)->block(60, function () {
            $lastCallAt = Cache::get('gemini-vision-last-call-at');

            if ($lastCallAt !== null) {
                $elapsed = microtime(true) - $lastCallAt;
                $remaining = self::MIN_SECONDS_BETWEEN_GEMINI_CALLS - $elapsed;

                if ($remaining > 0) {
                    usleep((int) ($remaining * 1_000_000));
                }
            }

            Cache::put('gemini-vision-last-call-at', microtime(true), 120);
        });
    }

    /**
     * Ngoài JSON bị cắt cụt, free tier còn hay gặp lỗi tạm thời do quá tải chung
     * ("currently experiencing high demand") — bắt \Throwable vì cả 2 loại lỗi đều
     * đáng thử lại, chỉ giới hạn cứng MAX_JSON_RETRIES lần nên không sợ lặp vô hạn.
     * Riêng lỗi VƯỢT QUOTA thật sự thì throttleGeminiCall() đã ngăn từ đầu (không còn
     * dựa vào retry để "vá" quota nữa — quota cần chờ tới phút sau mới có lại, backoff
     * ngắn không giải quyết được gì).
     *
     * @param  callable(): mixed  $makeResponse
     */
    private function generateWithRetry(MonasticDocument $document, callable $makeResponse): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_JSON_RETRIES; $attempt++) {
            $this->throttleGeminiCall();

            try {
                return $this->parseGeminiResponse($document, $makeResponse());
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($attempt < self::MAX_JSON_RETRIES) {
                    sleep($attempt);
                }
            }
        }

        throw $lastException;
    }

    /**
     * Free tier không tính tiền nên KHÔNG cần tính ai_cost_usd nữa — vẫn lưu lại số
     * token dùng để tiện theo dõi mức tiêu thụ so với giới hạn rate limit hàng ngày.
     */
    private function parseGeminiResponse(MonasticDocument $document, mixed $response): array
    {
        $usage = $response->usageMetadata;
        $document->update([
            'ai_input_tokens'  => $usage->promptTokenCount,
            'ai_output_tokens' => $usage->candidatesTokenCount,
        ]);

        if ($response->candidates[0]->finishReason?->value === 'MAX_TOKENS') {
            throw new \RuntimeException('AI bị cắt phản hồi giữa dòng — cần tăng maxOutputTokens trong MonasticImportService.');
        }

        $raw = $response->text();

        $sanitized = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/', ' ', $sanitized) ?? $sanitized;
        $data      = json_decode(trim($sanitized), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            throw new \RuntimeException(
                'Gemini không trả về JSON hợp lệ: '.json_last_error_msg().
                ' — đoạn đầu phản hồi: '.Str::limit(trim($sanitized), 300)
            );
        }

        return $data;
    }
}

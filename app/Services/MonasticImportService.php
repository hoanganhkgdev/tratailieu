<?php

namespace App\Services;

use App\Models\MonasticDocument;
use App\Models\MonasticProfile;
use App\Models\Province;
use App\Models\Temple;
use Gemini\Data\Blob;
use Gemini\Data\GenerationConfig;
use Gemini\Data\ThinkingConfig;
use Gemini\Enums\MimeType as GeminiMimeType;
use Gemini\Enums\ResponseMimeType;
use Gemini\Laravel\Facades\Gemini;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MonasticImportService
{
    /**
     * Giá gemini-flash-latest, ước tính theo bảng giá công khai Gemini Flash — dùng
     * chung cho cả 2 đường (text lẫn ảnh scan), xem analyze()/analyzeFromImages().
     */
    private const INPUT_COST_PER_TOKEN = 0.30 / 1_000_000;

    private const OUTPUT_COST_PER_TOKEN = 2.50 / 1_000_000;

    public function __construct(private DocumentParserService $parser) {}

    /**
     * Dưới ngưỡng này coi như PDF không có lớp text thật (chỉ là ảnh scan/chụp trang
     * giấy nhúng vào PDF) — đo thực tế: PDF scan cho ra chuỗi rỗng hoặc vài ký tự
     * rác, trong khi PDF có lớp text luôn cho ra hàng nghìn ký tự.
     */
    private const MIN_TEXT_LENGTH_FOR_TEXT_MODE = 200;

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

            if ($document->file_type === 'pdf' && mb_strlen(trim($text)) < self::MIN_TEXT_LENGTH_FOR_TEXT_MODE) {
                // File scan/chụp ảnh trang giấy, không có lớp text để đọc bằng cách
                // thường — chuyển sang cho AI đọc trực tiếp ảnh từng trang (vision).
                $data = $this->processScannedPdf($document);
            } else {
                $clarified = $this->clarifyCheckboxes($text);
                $data = $this->analyze($document, $clarified);

                // "Phân loại" chỉ có đúng 3 nhãn cố định — dù text đã rõ ràng, AI vẫn
                // thỉnh thoảng đọc sai khi phải xử lý đồng thời 30+ field khác trong
                // cùng 1 lượt gọi (đã kiểm chứng: tách riêng ra hỏi AI 1 mình thì luôn
                // đúng, nhưng lẫn trong toàn bộ phiếu thì hay trả dư). Tự parse lại
                // bằng PHP thay vì tin AI — đáng tin cậy tuyệt đối vì chỉ cần so khớp
                // nhãn [ĐÃ_CHỌN]/[chưa_chọn] ngay trước 1 trong 3 nhãn cố định, không
                // cần suy luận ngữ nghĩa. (Vision đọc trực tiếp checkbox trong ảnh nên
                // không cần bước này — AI thấy ô nào tô đậm/tick thật, không phải đoán
                // qua ký hiệu Unicode.)
                $deterministicClassification = $this->extractClassification($clarified);
                if ($deterministicClassification !== null) {
                    $data['classification'] = $deterministicClassification;
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
        $province = Province::findByNameOrAlias($data['province_name'] ?? null);

        // Khác với tự viện (bắt buộc phải xác định được tỉnh mới lưu, vì mã tự viện
        // đánh số theo từng tỉnh), hồ sơ tăng ni vẫn lưu được dù chưa rõ tỉnh/tự viện
        // — province_id/temple_id chỉ là dữ liệu tham chiếu thêm, sửa tay sau vẫn được,
        // không có gì phụ thuộc cứng vào nó như code tự viện.
        $temple = null;

        if ($province && filled($data['temple_name'] ?? null)) {
            $temple = $this->findTemple($province, $data['temple_name']);
        }

        // Mỗi document ứng với đúng 1 hồ sơ — updateOrCreate theo monastic_document_id
        // để bấm "Xử lý lại" cập nhật đúng bản ghi cũ thay vì tạo hồ sơ trùng lặp.
        MonasticProfile::updateOrCreate(
            ['monastic_document_id' => $document->id],
            [
                'temple_id'                  => $temple?->id,
                'province_id'                => $province?->id,
                'full_name'                  => $data['full_name'] ?? 'Chưa xác định',
                'religious_name'             => $data['religious_name'] ?? null,
                'birth_date'                 => $this->toNullableDate($data['birth_date'] ?? null),
                'gender'                     => $data['gender'] ?? null,
                'ethnicity'                  => $data['ethnicity'] ?? null,
                'nationality'                => $data['nationality'] ?? null,
                'id_number'                  => $this->truncate($data['id_number'] ?? null, 30),
                'id_issued_date'             => $this->toNullableDate($data['id_issued_date'] ?? null),
                'id_issued_place'            => $data['id_issued_place'] ?? null,
                'hometown'                   => $data['hometown'] ?? null,
                'permanent_address'          => $data['permanent_address'] ?? null,
                'current_address'            => $data['current_address'] ?? null,
                'monastic_cert_number'       => $this->truncate($data['monastic_cert_number'] ?? null, 100),
                'monastic_cert_date'         => $this->toNullableDate($data['monastic_cert_date'] ?? null),
                'religion'                   => $data['religion'] ?? null,
                'religious_org'              => $data['religious_org'] ?? null,
                'sect'                       => $data['sect'] ?? null,
                'classification'             => $this->toClassificationArray($data['classification'] ?? null),
                'current_position'           => $data['current_position'] ?? null,
                'ordination_date'            => $this->toNullableDate($data['ordination_date'] ?? null),
                'concurrent_position'        => $data['concurrent_position'] ?? null,
                'activity_scope'             => $data['activity_scope'] ?? null,
                'notes'                      => $data['notes'] ?? null,
                'education_level'            => $data['education_level'] ?? null,
                'professional_qualification' => $data['professional_qualification'] ?? null,
                'religious_education_level'  => $data['religious_education_level'] ?? null,
                'training_institutions'      => $data['training_institutions'] ?? null,
                'languages'                  => $data['languages'] ?? null,
                'activity_history'           => $data['activity_history'] ?? null,
                'commendation_discipline'    => $data['commendation_discipline'] ?? null,
                'violations'                 => $data['violations'] ?? null,
                'congress_term'              => $data['congress_term'] ?? null,
                'phone'                      => $this->truncate($data['phone'] ?? null, 30),
                'email'                      => $data['email'] ?? null,
                'status'                     => $data['status'] ?? null,
            ]
        );

        $document->update([
            'temple_id'      => $temple?->id,
            'province_id'    => $province?->id,
            'status'         => 'ready',
            'processed_at'   => now(),
            'error_message'  => null,
            'extracted_json' => $data,
        ]);
    }

    /**
     * Đối chiếu tên tự viện AI trích được (từ "nơi hành đạo"/"nơi ở hiện tại") với tự
     * viện đã có trong CÙNG tỉnh — khớp chuỗi con, phân biệt dấu chuẩn (collation mặc
     * định của MySQL coi khác dấu tiếng Việt là như nhau, xem TempleSearchService).
     */
    private function findTemple(Province $province, string $templeName): ?Temple
    {
        $isMysql = DB::getDriverName() === 'mysql';
        $templeName = trim($templeName);

        // Thử khớp CHÍNH XÁC trước (bỏ qua hoa/thường, phân biệt dấu chuẩn) — nếu
        // không, "Chùa Phật Quang" sẽ khớp LIKE vào cả "CHÙA PHẬT QUANG PHỔ CHIẾU"
        // hay "CHÙA PHẬT QUANG CHÁNH GIÁC" (tên khác, chỉ trùng 1 đoạn) và lấy nhầm
        // record đầu tiên tìm được thay vì đúng chùa cùng tên tuyệt đối.
        $exact = Temple::where('province_id', $province->id)
            ->where(function ($q) use ($templeName, $isMysql) {
                $isMysql
                    ? $q->whereRaw('name COLLATE utf8mb4_0900_as_ci = ?', [$templeName])
                    : $q->where('name', $templeName);
            })
            ->first();

        if ($exact) {
            return $exact;
        }

        return Temple::where('province_id', $province->id)
            ->where(function ($q) use ($templeName, $isMysql) {
                $isMysql
                    ? $q->whereRaw('name COLLATE utf8mb4_0900_as_ci LIKE ?', ['%'.$templeName.'%'])
                    : $q->where('name', 'LIKE', '%'.$templeName.'%');
            })
            ->first();
    }

    private function truncate(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr(trim($value), 0, $length);
    }

    /**
     * Dù text đã giữ đúng ☒/☐ (xem DocumentParserService::extractTextPreservingCheckboxes()),
     * AI vẫn thỉnh thoảng đọc nhầm 2 ký hiệu Unicode gần giống nhau này (đã kiểm chứng
     * thực tế: "☒ Chức sắc☒ Chức việc☐ Nhà tu hành" — AI vẫn trả về cả 3, kể cả ô
     * "Nhà tu hành" rõ ràng KHÔNG được tick). Thay 2 ký hiệu bằng nhãn chữ tường minh
     * trước khi gửi cho AI — dễ đọc đúng hơn hẳn so với phân biệt hình dạng ký tự.
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
     * Parse trực tiếp mục "Phân loại" bằng PHP thay vì tin AI — chỉ có đúng 3 nhãn cố
     * định (Chức sắc/Chức việc/Nhà tu hành), mỗi nhãn có [ĐÃ_CHỌN] hoặc [chưa_chọn]
     * đứng ngay trước (xem clarifyCheckboxes()). Trả null nếu không tìm thấy mục này
     * trong văn bản (để finalize() giữ nguyên giá trị AI trả, còn hơn ép về mảng rỗng).
     *
     * @return array<int, string>|null
     */
    private function extractClassification(string $clarifiedText): ?array
    {
        $pos = mb_stripos($clarifiedText, 'Phân loại');

        if ($pos === false) {
            return null;
        }

        // Đủ dài để chứa cả 3 nhãn (thực tế đo được ~110 ký tự) nhưng không dài tới
        // mức lấn sang mục kế tiếp có thể vô tình chứa lại các từ "chức sắc/chức việc".
        $section = mb_substr($clarifiedText, $pos, 250);

        // Câu mô tả ngay đầu mục "(có thể vừa là chức sắc, vừa là chức việc):" nhắc
        // lại đúng 2 trong 3 nhãn này TRƯỚC danh sách checkbox thật — nếu không bỏ
        // qua đoạn này, mb_stripos() (không phân biệt hoa/thường) sẽ khớp NHẦM vào
        // đây trước, luôn thấy "before" không có [ĐÃ_CHỌN] dù nhãn thật có tick.
        $afterDescription = mb_strpos($section, '):');
        if ($afterDescription !== false) {
            $section = mb_substr($section, $afterDescription + 2);
        }

        $labels = [
            'chuc_sac'    => 'Chức sắc',
            'chuc_viec'   => 'Chức việc',
            'nha_tu_hanh' => 'Nhà tu hành',
        ];

        $result = [];

        foreach ($labels as $key => $label) {
            $labelPos = mb_stripos($section, $label);

            if ($labelPos === false) {
                continue;
            }

            $before = mb_substr($section, max(0, $labelPos - 20), 20);

            if (mb_stripos($before, '[ĐÃ_CHỌN]') !== false) {
                $result[] = $key;
            }
        }

        return $result;
    }

    /**
     * AI trả ngày dạng "dd/mm/yyyy" (đúng định dạng phiếu gốc) hoặc chuỗi rỗng/"..."
     * khi phiếu bỏ trống field đó — không phải date hợp lệ thì trả null thay vì để
     * lỗi DB chặn cả hồ sơ (cùng nguyên tắc với toNullableInt() ở TempleImportService).
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

    private const INSTRUCTIONS = <<<PROMPT
Hãy phân tích văn bản trích từ "Phiếu thu thập thông tin dữ liệu về chức sắc, chức việc, nhà tu
hành tôn giáo (Phiếu số 3)" — hồ sơ CÁ NHÂN của 1 tăng/ni Phật giáo Việt Nam (cung cấp ở cuối
prompt) và trả về JSON.

Phiếu có các nhóm thông tin theo thứ tự: (I) Định danh & cá nhân cơ bản, (II) Hành đạo & chuyên
môn tôn giáo, (III) Đào tạo, (IV) Hoạt động & bổ nhiệm, (V) Liên hệ & tình trạng. Lưu ý:
- Field nào phiếu để trống, gạch chấm "...", hoặc không ghi gì thì trả null, TUYỆT ĐỐI không bịa.
- Ngày tháng giữ nguyên định dạng "dd/mm/yyyy" như trong phiếu.
- Các mục lựa chọn (checkbox) trong phiếu đã được đánh dấu rõ bằng nhãn chữ: "[ĐÃ_CHỌN]" đứng
  ngay TRƯỚC tên lựa chọn nghĩa là mục đó ĐƯỢC chọn, "[chưa_chọn]" nghĩa là KHÔNG được chọn. LUÔN
  dựa CHÍNH XÁC vào 2 nhãn này để biết mục nào được chọn — TUYỆT ĐỐI không tự suy đoán theo ngữ
  nghĩa hay theo mục đứng đầu tiên.
- "Phân loại" là checkbox có thể chọn nhiều ô — trả về mảng CHỈ gồm các giá trị có nhãn
  "[ĐÃ_CHỌN]" đứng trước, trong số: "chuc_sac" (Chức sắc), "chuc_viec" (Chức việc), "nha_tu_hanh"
  (Nhà tu hành). Bỏ qua hoàn toàn các mục có nhãn "[chưa_chọn]".
- "Nơi hành đạo/hoạt động" hoặc "Nơi ở hiện tại" thường ghi tên tự viện + địa chỉ (vd "Chùa Phật
  Quang, số 83 Quang Trung, phường Rạch Giá, tỉnh An Giang") — tách riêng PHẦN TÊN TỰ VIỆN (bỏ địa
  chỉ) vào "temple_name" để hệ thống tự đối chiếu, và ghi TÊN TỈNH/THÀNH vào "province_name".
- "Tình trạng hiện tại" phiếu có 2 nhóm checkbox tuỳ đối tượng (chức sắc/chức việc: đang hoạt
  động/hưu trí/cách chức/đã chết; nhà tu hành: đang tu hành/hoàn tục/đã chết/tẩn xuất) — chỉ lấy
  đúng 1 giá trị có nhãn "[ĐÃ_CHỌN]" thành chuỗi text, ví dụ "Đang hoạt động" hoặc "Đang tu hành".

Trả về JSON đúng định dạng sau (field nào phiếu không có/để trống thì null):
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
  "current_address": "nơi ở hiện tại (nguyên văn, gồm cả tên tự viện + địa chỉ)",
  "temple_name": "chỉ phần tên tự viện tách từ current_address, không kèm địa chỉ",
  "province_name": "tên tỉnh/thành phố (vd: An Giang, TP. Hồ Chí Minh)",
  "monastic_cert_number": "số chứng nhận Tăng ni",
  "monastic_cert_date": "dd/mm/yyyy",
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

Văn bản cần phân tích:
PROMPT;

    /**
     * Dùng cho file scan/chụp ảnh (không có lớp text) — cùng JSON schema với
     * INSTRUCTIONS ở trên, chỉ khác phần hướng dẫn đầu vì input là ẢNH từng trang chứ
     * không phải văn bản.
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
- "Nơi hành đạo/hoạt động" hoặc "Nơi ở hiện tại" thường ghi tên tự viện + địa chỉ (vd "Chùa Phật
  Quang, số 83 Quang Trung, phường Rạch Giá, tỉnh An Giang") — tách riêng PHẦN TÊN TỰ VIỆN (bỏ địa
  chỉ) vào "temple_name" để hệ thống tự đối chiếu, và ghi TÊN TỈNH/THÀNH vào "province_name".
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
  "current_address": "nơi ở hiện tại (nguyên văn, gồm cả tên tự viện + địa chỉ)",
  "temple_name": "chỉ phần tên tự viện tách từ current_address, không kèm địa chỉ",
  "province_name": "tên tỉnh/thành phố (vd: An Giang, TP. Hồ Chí Minh)",
  "monastic_cert_number": "số chứng nhận Tăng ni",
  "monastic_cert_date": "dd/mm/yyyy",
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

    private function analyze(MonasticDocument $document, string $text): array
    {
        // Hồ sơ 1 người ngắn hơn nhiều so với danh sách chức sắc cả tự viện — không
        // cần giới hạn token lớn như TempleImportService.
        $excerpt = Str::limit($text, 8000);

        $response = Gemini::generativeModel(model: 'gemini-flash-latest')
            ->withGenerationConfig(new GenerationConfig(
                maxOutputTokens: 2000,
                temperature: 0,
                responseMimeType: ResponseMimeType::APPLICATION_JSON,
                thinkingConfig: new ThinkingConfig(includeThoughts: false, thinkingBudget: 0),
            ))
            ->generateContent(self::INSTRUCTIONS."\n\n".$excerpt);

        return $this->parseGeminiResponse($document, $response);
    }

    /**
     * File scan/chụp ảnh trang giấy không có lớp text — gửi thẳng ảnh từng trang cho
     * AI đọc bằng vision thay vì text. Checkbox trong ảnh AI thấy trực tiếp (ô nào
     * thật sự được tick) nên KHÔNG cần bước clarifyCheckboxes()/extractClassification()
     * như đường text — đó là 2 kỹ thuật riêng để bù cho việc mất ký hiệu ☒/☐ khi trích
     * text, không áp dụng khi AI đọc ảnh gốc trực tiếp.
     *
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

        $response = Gemini::generativeModel(model: 'gemini-flash-latest')
            ->withGenerationConfig(new GenerationConfig(
                // BẮT BUỘC — nếu không set, model tự bật "thinking" ngầm (đã kiểm chứng
                // thực tế: model mặc định sinh ~1900 token "thoughts" ẩn dù không cần,
                // đúng nguyên nhân từng làm chi phí Gemini tăng vọt trước đây). Set 0 để
                // khoá cứng, đã đo lại xác nhận thoughts luôn về 0 mà độ chính xác đọc
                // ảnh không đổi.
                thinkingConfig: new ThinkingConfig(includeThoughts: false, thinkingBudget: 0),
                responseMimeType: ResponseMimeType::APPLICATION_JSON,
            ))
            ->generateContent($content);

        return $this->parseGeminiResponse($document, $response);
    }

    private function parseGeminiResponse(MonasticDocument $document, mixed $response): array
    {
        $usage = $response->usageMetadata;

        $document->update([
            'ai_input_tokens'  => $usage->promptTokenCount,
            'ai_output_tokens' => $usage->candidatesTokenCount,
            'ai_cost_usd'      => ($usage->promptTokenCount * self::INPUT_COST_PER_TOKEN)
                + ($usage->candidatesTokenCount * self::OUTPUT_COST_PER_TOKEN),
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

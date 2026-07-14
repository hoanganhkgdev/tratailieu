<?php

namespace App\Services;

use App\Models\MonasticDocument;
use App\Models\MonasticProfile;
use App\Models\Province;
use App\Models\Temple;
use Illuminate\Support\Facades\DB;

/**
 * KHÔNG dùng AI — "Phiếu số 3" là mẫu chuẩn hóa nhà nước, nhãn từng field cố định
 * tuyệt đối (đã kiểm chứng qua nhiều file khác nhau), nên đọc thẳng bằng regex theo
 * nhãn (MonasticFormParserService) là đủ chính xác và MIỄN PHÍ — mục đích cuối cùng
 * chỉ là trích chữ để tra cứu + tải lại file gốc, không cần hiểu ngữ nghĩa sâu như AI.
 *
 * File scan/chụp ảnh (không có lớp text) hoặc không khớp đúng mẫu phiếu này thì
 * KHÔNG tự động trích được — đánh dấu "failed" kèm lý do rõ ràng để nhập tay, thay vì
 * gọi AI đọc ảnh như trước (từng dùng Gemini vision, đã bỏ hẳn — xem lịch sử commit).
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
            // bắt được, coi như không có text dùng được.
            $textUsable = mb_strlen(trim($text)) >= self::MIN_TEXT_LENGTH_FOR_TEXT_MODE && mb_check_encoding($text, 'UTF-8');

            if (! $textUsable) {
                throw new \RuntimeException('File không có lớp text đọc được (file scan/chụp ảnh, hoặc lỗi encoding font) — không còn dùng AI để đọc ảnh, cần nhập tay.');
            }

            $clarified = $this->clarifyCheckboxes($text);
            $data = $this->formParser->parse($clarified);

            if ($data === null) {
                throw new \RuntimeException('Không nhận diện được đúng mẫu "Phiếu số 3" (nhãn field không khớp) — kiểm tra lại định dạng file hoặc nhập tay.');
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
     * Đối chiếu tên tự viện trích được (từ "nơi hành đạo"/"nơi ở hiện tại") với tự
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
}

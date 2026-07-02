<?php

namespace App\Services;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\Monastic;
use App\Models\Province;
use App\Models\Temple;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MonasticImportService
{
    public function __construct(private DocumentParserService $parser) {}

    public function analyze(string $filePath, string $fileType): array
    {
        $text    = $this->parser->extractText($filePath, $fileType);
        $excerpt = Str::limit($text, 6000);

        $prompt = <<<PROMPT
Hãy đọc nội dung "Phiếu thu thập thông tin về chức sắc, chức việc, nhà tu hành tôn giáo (Phiếu số 3)" trích dưới đây và trích xuất thông tin thành JSON.

Văn bản:
{$excerpt}

Trả về JSON đúng cấu trúc sau (nếu không tìm thấy thông tin thì để null, mảng rỗng cho classifications/activities):
{
  "full_name": "họ và tên khai sinh",
  "religious_name": "pháp danh / tên trong tôn giáo",
  "date_of_birth": "YYYY-MM-DD hoặc null",
  "gender": "nam | nu",
  "ethnicity": "dân tộc",
  "nationality": "quốc tịch",
  "id_type": "cmnd | cccd | ho_chieu | chung_nhan_tang_ni | khac",
  "id_number": "số giấy tờ tùy thân",
  "id_issued_date": "YYYY-MM-DD hoặc null",
  "id_issued_place": "nơi cấp giấy tờ",
  "hometown": "quê quán",
  "permanent_address": "địa chỉ thường trú",
  "current_address": "nơi ở hiện tại",
  "monastic_cert_number": "số chứng nhận Tăng Ni",
  "monastic_cert_date": "YYYY-MM-DD hoặc null",
  "religion": "tôn giáo, mặc định Phật giáo",
  "religious_organization": "tổ chức tôn giáo trực thuộc",
  "sect": "hệ phái / dòng tu",
  "temple_name": "tên chùa / cơ sở tôn giáo nơi đang sinh hoạt, hành đạo",
  "province_name": "tỉnh/thành phố nơi sinh hoạt, hành đạo (suy ra từ địa chỉ chùa, nơi ở hiện tại hoặc thường trú — ví dụ: An Giang, TP. Hồ Chí Minh)",
  "classifications": ["chuc_sac" hoặc "chuc_viec" hoặc "nha_tu_hanh", ...theo những gì văn bản thể hiện],
  "rank": "phẩm trật — chọn 1 trong: hoa_thuong, thuong_toa, dai_duc, sa_di (nếu là nam) hoặc ni_truong, ni_su, su_co, sa_di_ni (nếu là nữ)",
  "current_position": "chức vụ / phẩm vị hiện đang đảm nhiệm",
  "appointment_date": "ngày thụ phong / bổ nhiệm, định dạng YYYY-MM-DD hoặc null",
  "concurrent_position": "chức vụ kiêm nhiệm (nếu có)",
  "activity_scope": "toan_quoc | mot_so_tinh | mot_tinh",
  "activity_scope_detail": "chi tiết phạm vi hoạt động (vd tên các tỉnh)",
  "notes": "ghi chú khác liên quan đến hành đạo",
  "education_level": "trình độ học vấn phổ thông",
  "professional_qualification": "trình độ chuyên môn / bằng cấp đời",
  "buddhist_education_level": "trình độ Phật học / tu học",
  "training_institutions": "các cơ sở đào tạo tôn giáo đã theo học",
  "languages": "ngoại ngữ / tiếng dân tộc biết sử dụng",
  "phone": "số điện thoại liên hệ",
  "email": "địa chỉ email",
  "status": "dang_hoat_dong | huu_tri | cach_chuc | hoan_tuc | tan_xuat | da_chet (mặc định dang_hoat_dong nếu không có thông tin khác)",
  "activities": [
    {
      "from_date": "YYYY-MM-DD hoặc null",
      "to_date": "YYYY-MM-DD hoặc null",
      "place": "nơi hành đạo / hoạt động trong giai đoạn này",
      "position": "chức vụ đảm nhận trong giai đoạn này",
      "term_period": "nhiệm kỳ đại hội nếu có, vd: 2020 - 2025",
      "commendation": "khen thưởng trong giai đoạn (nếu có)",
      "violation": "kỷ luật / vi phạm trong giai đoạn (nếu có)"
    }
  ]
}

Chỉ trả về JSON thuần, không giải thích thêm.
PROMPT;

        $response = OpenAI::chat()->create([
            'model'           => 'gpt-4o-mini',
            'messages'        => [['role' => 'user', 'content' => $prompt]],
            'response_format' => ['type' => 'json_object'],
        ]);
        $raw  = $response->choices[0]->message->content;
        $data = json_decode(trim($raw), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $data = $this->fallback();
        }

        $data['classifications'] = is_array($data['classifications'] ?? null) ? $data['classifications'] : [];
        $data['activities']      = is_array($data['activities'] ?? null) ? $data['activities'] : [];

        // Đối chiếu tên chùa AI nhận diện với danh sách chùa đã có trong hệ thống.
        // KHÔNG tự tạo chùa mới — nếu không khớp, để trống temple_id và yêu cầu
        // người dùng tự chọn (hoặc bỏ trống) ở bước xem trước.
        $temple = $this->matchTemple($data['temple_name'] ?? null);
        $data['temple_id'] = $temple?->id;

        // Đối chiếu tỉnh/thành với danh sách 34 tỉnh chính thức (qua tên hoặc tên cũ/alias).
        // Nếu AI không nhận diện được hoặc không khớp, thử suy ra từ tỉnh của chùa đã khớp.
        $province = Province::findByNameOrAlias($data['province_name'] ?? null) ?? $temple?->province;
        $data['province_id'] = $province?->id;

        return $data;
    }

    private function str($value): ?string
    {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $value)));
        }

        return $value !== null ? (string) $value : null;
    }

    public function import(array $data): Monastic
    {
        $monastic = Monastic::create([
            'temple_id'                  => $data['temple_id'] ?? null,
            'province_id'                => $data['province_id'] ?? null,
            'full_name'                  => $this->str($data['full_name'] ?? null) ?: 'Chưa xác định',
            'religious_name'             => $this->str($data['religious_name'] ?? null),
            'date_of_birth'              => $data['date_of_birth'] ?? null,
            'gender'                     => in_array($data['gender'] ?? '', ['nam', 'nu']) ? $data['gender'] : 'nam',
            'ethnicity'                  => $this->str($data['ethnicity'] ?? null),
            'nationality'                => $this->str($data['nationality'] ?? null) ?: 'Việt Nam',
            'id_type'                    => in_array($data['id_type'] ?? '', ['cmnd', 'cccd', 'ho_chieu', 'chung_nhan_tang_ni', 'khac']) ? $data['id_type'] : null,
            'id_number'                  => $this->str($data['id_number'] ?? null),
            'id_issued_date'             => $data['id_issued_date'] ?? null,
            'id_issued_place'            => $this->str($data['id_issued_place'] ?? null),
            'hometown'                   => $this->str($data['hometown'] ?? null),
            'permanent_address'          => $this->str($data['permanent_address'] ?? null),
            'current_address'            => $this->str($data['current_address'] ?? null),
            'monastic_cert_number'       => $this->str($data['monastic_cert_number'] ?? null),
            'monastic_cert_date'         => $data['monastic_cert_date'] ?? null,
            'religion'                   => $this->str($data['religion'] ?? null) ?: 'Phật giáo',
            'religious_organization'     => $this->str($data['religious_organization'] ?? null),
            'sect'                       => $this->str($data['sect'] ?? null),
            'classifications'            => $data['classifications'] ?? [],
            'rank'                       => $data['rank'] ?? null,
            'current_position'           => $this->str($data['current_position'] ?? null),
            'appointment_date'           => $data['appointment_date'] ?? null,
            'concurrent_position'        => $this->str($data['concurrent_position'] ?? null),
            'activity_scope'             => in_array($data['activity_scope'] ?? '', ['toan_quoc', 'mot_so_tinh', 'mot_tinh']) ? $data['activity_scope'] : null,
            'activity_scope_detail'      => $this->str($data['activity_scope_detail'] ?? null),
            'notes'                      => $this->str($data['notes'] ?? null),
            'education_level'            => $this->str($data['education_level'] ?? null),
            'professional_qualification' => $this->str($data['professional_qualification'] ?? null),
            'buddhist_education_level'   => $this->str($data['buddhist_education_level'] ?? null),
            'training_institutions'      => $this->str($data['training_institutions'] ?? null),
            'languages'                  => $this->str($data['languages'] ?? null),
            'phone'                      => $this->str($data['phone'] ?? null),
            'email'                      => $this->str($data['email'] ?? null),
            'status'                     => in_array($data['status'] ?? '', ['dang_hoat_dong', 'huu_tri', 'cach_chuc', 'hoan_tuc', 'tan_xuat', 'da_chet']) ? $data['status'] : 'dang_hoat_dong',
        ]);

        foreach ($data['activities'] ?? [] as $activity) {
            if (empty(array_filter($activity))) {
                continue;
            }

            $monastic->activities()->create([
                'from_date'    => $activity['from_date'] ?? null,
                'to_date'      => $activity['to_date'] ?? null,
                'place'        => $this->str($activity['place'] ?? null),
                'position'     => $this->str($activity['position'] ?? null),
                'term_period'  => $this->str($activity['term_period'] ?? null),
                'commendation' => $this->str($activity['commendation'] ?? null),
                'violation'    => $this->str($activity['violation'] ?? null),
            ]);
        }

        $this->attachOriginalFile($monastic, $data);

        return $monastic;
    }

    /**
     * Lưu lại phiếu gốc (PDF/DOCX đã upload) làm tài liệu đính kèm hồ sơ — thay vì
     * xoá đi sau khi trích xuất — để AI tra cứu có thể tìm thấy và đưa ra link tải.
     */
    private function attachOriginalFile(Monastic $monastic, array $data): void
    {
        $tempPath = $data['_file_path'] ?? null;

        if (empty($tempPath) || ! Storage::disk('public')->exists($tempPath)) {
            return;
        }

        $fileName = $data['_file_name'] ?? basename($tempPath);
        $province = $monastic->province_id ? Province::find($monastic->province_id) : null;
        $provinceSlug = $province ? Str::slug($province->name) : 'chua-xac-dinh';
        $newPath  = "tang-ni/{$provinceSlug}/" . Str::random(12) . '_' . $fileName;

        Storage::disk('public')->move($tempPath, $newPath);

        $document = Document::create([
            'temple_id'   => $monastic->temple_id,
            'monastic_id' => $monastic->id,
            'uploaded_by' => Auth::id() ?? \App\Models\User::first()?->id,
            'title'       => 'Phiếu thông tin Tăng Ni: ' . $monastic->full_name,
            'description' => 'Phiếu số 3 gốc dùng để nhập hồ sơ này bằng AI.',
            'file_path'   => $newPath,
            'file_name'   => $fileName,
            'file_type'   => $data['_file_type'] ?? 'pdf',
            'file_size'   => Storage::disk('public')->size($newPath),
            'status'      => 'pending',
        ]);

        ProcessDocumentJob::dispatch($document);
    }

    private function matchTemple(?string $name): ?Temple
    {
        if (empty($name)) {
            return null;
        }

        $slug = Str::slug($name);

        return Temple::query()
            ->where('slug', $slug)
            ->orWhere('name', $name)
            ->orWhere('name', 'like', "%{$name}%")
            ->first();
    }

    private function fallback(): array
    {
        return [
            'full_name'       => null,
            'religious_name'  => null,
            'gender'          => 'nam',
            'temple_name'     => null,
            'province_name'   => null,
            'classifications' => [],
            'activities'      => [],
            'status'          => 'dang_hoat_dong',
        ];
    }
}

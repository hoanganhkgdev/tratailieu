<?php

namespace App\Services;

use App\Models\Province;

/**
 * "Phiếu số 3" là mẫu chuẩn hóa của nhà nước — nhãn từng field CỐ ĐỊNH tuyệt đối,
 * giống hệt nhau giữa mọi file (đã kiểm chứng qua nhiều file docx VÀ PDF text-layer
 * khác nhau, khác người/khác chùa: "Họ và tên khai sinh:", "Ngày, tháng, năm sinh:"...
 * luôn y hệt). Tận dụng điều này để trích xuất KHÔNG CẦN AI cho file có lớp text thật
 * (docx/PDF gõ máy) — miễn phí, nhanh hơn, và đáng tin hơn AI (không random, không bị
 * cắt JSON giữa chừng như Gemini). Chỉ file scan/viết tay thật sự (không có lớp text)
 * mới cần AI đọc ảnh — xem MonasticImportService::process().
 */
class MonasticFormParserService
{
    /**
     * Text THUẦN (không tự escape regex tay — locateLabels() tự lo qua preg_quote(),
     * tránh lặp lại lỗi ký tự "/" có sẵn trong chính nhãn tiếng Việt như "Hệ phái/Dòng
     * tu" phá vỡ delimiter "/" của regex).
     *
     * Chỉ cần phần "lõi" đủ để nhận diện — KHÔNG cần chép hết phần mô tả/ví dụ trong
     * ngoặc (vd "(ví dụ: 12/12)") vì locateLabels() tìm dấu ":" GẦN NHẤT sau nhãn,
     * không bắt buộc đứng ngay sau — xử lý được cả trường hợp có ngoặc chen giữa.
     *
     * Thứ tự khai báo không quan trọng — sliceValues() tự sắp lại theo vị trí THẬT
     * xuất hiện trong văn bản trước khi cắt. Giá trị mỗi field là đoạn text từ NGAY
     * SAU dấu ":" của nhãn này tới NGAY TRƯỚC nhãn kế tiếp (theo vị trí thật).
     *
     * "*" trước nhãn không bắt buộc — 2 field "Ngày cấp"/"Nơi cấp" là field con nằm
     * trong dòng của "Số CCCD" (mục 8), không có "*" riêng.
     */
    private const FIELD_LABELS = [
        'full_name'                  => 'Họ và tên khai sinh',
        'religious_name'             => 'Tên trong tôn giáo (Tên gọi chính thức được sử dụng trong tôn giáo)',
        'birth_date'                 => 'Ngày, tháng, năm sinh',
        'gender'                     => 'Giới tính',
        'ethnicity'                  => 'Dân tộc',
        'nationality'                => 'Quốc tịch',
        'id_number'                  => 'Số Căn cước công dân (CCCD)',
        'id_issued_date'             => 'Ngày cấp',
        'id_issued_place'            => 'Nơi cấp',
        'hometown'                   => 'Quê quán (Theo thông tin trên VneID)',
        'permanent_address'          => 'Địa chỉ thường trú (Theo thông tin trên VneID)',
        'current_address'            => 'Nơi ở hiện tại',
        'monastic_cert_number'       => 'Số chứng nhận Tăng ni',
        'monastic_cert_date'         => 'Ngày tháng cấp',
        'religion'                   => 'Tôn giáo',
        'religious_org'              => 'Tổ chức tôn giáo (Tên tổ chức mà cá nhân là thành viên)',
        'sect'                       => 'Hệ phái/Dòng tu',
        'classification'             => 'Phân loại (có thể vừa là chức sắc, vừa là chức việc)',
        'current_position'           => 'Chức vụ/Phẩm vị hiện tại',
        'ordination_date'            => 'Ngày thụ phong/bổ nhiệm',
        'concurrent_position'        => 'Chức vụ kiêm nhiệm',
        'activity_scope'             => 'Phạm vi hoạt động',
        'notes'                      => 'Ghi chú',
        'education_level'            => 'Trình độ học vấn phổ thông',
        'professional_qualification' => 'Trình độ chuyên môn',
        'religious_education_level'  => 'Trình độ tu học',
        'training_institutions'      => 'Cơ sở đào tạo tôn giáo đã theo học',
        'languages'                  => 'Ngoại ngữ/Tiếng dân tộc khác',
        '_activity_period'           => 'Từ ngày – Đến ngày',
        '_activity_place'            => 'Nơi hành đạo/hoạt động',
        '_activity_position'         => 'Chức vụ đảm nhận',
        'commendation_discipline'    => 'Khen thưởng/kỷ luật',
        'violations'                 => 'Các khiếu kiện, vi phạm pháp luật',
        'congress_term'              => 'Nhiệm kỳ đại hội từ năm',
        'phone'                      => 'Số điện thoại',
        'email'                      => 'Email',
        'status'                     => 'Tình trạng hiện tại',
    ];

    /**
     * Tiêu đề mục I-V — KHÔNG có dấu ":" nên không tự nhiên trở thành 1 "nhãn" như
     * FIELD_LABELS, nhưng vẫn PHẢI tính vào vị trí ranh giới — nếu không, tiêu đề mục
     * (vd "II. NHÓM THÔNG TIN HÀNH ĐẠO VÀ CHUYÊN MÔN TÔN GIÁO") sẽ bị dính vào ĐUÔI
     * giá trị của field NGAY TRƯỚC đó (đã tái hiện thực tế: "monastic_cert_date" nuốt
     * luôn cả dòng tiêu đề mục II phía sau nó).
     */
    private const SECTION_HEADERS = [
        'I. THÔNG TIN ĐỊNH DANH VÀ CÁ NHÂN CƠ BẢN',
        'II. NHÓM THÔNG TIN HÀNH ĐẠO VÀ CHUYÊN MÔN TÔN GIÁO',
        'III. NHÓM THÔNG TIN VỀ QUÁ TRÌNH ĐÀO TẠO',
        'IV. NHÓM THÔNG TIN VỀ QUÁ TRÌNH HOẠT ĐỘNG VÀ BỔ NHIỆM',
        'V. NHÓM THÔNG TIN LIÊN HỆ VÀ TÌNH TRẠNG',
        // Phiếu gốc đánh số nhảy thẳng V → VII (không có mục VI) — giữ đúng như bản
        // gốc. Bắt buộc phải có mốc này, không thì field "status" (Tình trạng hiện
        // tại, ngay trước mục này) sẽ nuốt luôn cả checkbox "Loại giấy tờ tùy thân"
        // của mục VII vào chung — đã tái hiện thực tế bug này.
        'VII. TÀI LIỆU ĐÍNH KÈM',
    ];

    /**
     * Dưới ngưỡng này coi như KHÔNG PHẢI đúng mẫu phiếu này (template khác, hoặc text
     * hỏng nặng) — nơi gọi nên fallback sang AI thay vì ép nhận kết quả thiếu be bét.
     */
    private const MIN_LABELS_MATCHED = 15;

    /**
     * @return array<string, mixed>|null null nghĩa là không nhận diện được đây có đúng
     *                                    mẫu "Phiếu số 3" hay không.
     */
    public function parse(string $clarifiedText): ?array
    {
        $positions = $this->locateLabels($clarifiedText);

        if (! isset($positions['full_name']) || count($positions) < self::MIN_LABELS_MATCHED) {
            return null;
        }

        $raw = $this->sliceValues($clarifiedText, $positions);

        $data = [
            'full_name'                  => $this->clean($raw['full_name'] ?? null),
            'religious_name'             => $this->clean($raw['religious_name'] ?? null),
            'birth_date'                 => $this->clean($raw['birth_date'] ?? null),
            'gender'                     => $this->clean($raw['gender'] ?? null),
            'ethnicity'                  => $this->clean($raw['ethnicity'] ?? null),
            'nationality'                => $this->clean($raw['nationality'] ?? null),
            'id_number'                  => $this->clean($raw['id_number'] ?? null),
            'id_issued_date'             => $this->clean($raw['id_issued_date'] ?? null),
            'id_issued_place'            => $this->clean($raw['id_issued_place'] ?? null),
            'hometown'                   => $this->clean($raw['hometown'] ?? null),
            'permanent_address'          => $this->clean($raw['permanent_address'] ?? null),
            'current_address'            => $this->clean($raw['current_address'] ?? null),
            'monastic_cert_number'       => $this->clean($raw['monastic_cert_number'] ?? null),
            'monastic_cert_date'         => $this->clean($raw['monastic_cert_date'] ?? null),
            'religion'                   => $this->clean($raw['religion'] ?? null),
            'religious_org'              => $this->clean($raw['religious_org'] ?? null),
            'sect'                       => $this->clean($raw['sect'] ?? null),
            'classification'             => $this->parseClassification($raw['classification'] ?? ''),
            'current_position'           => $this->clean($raw['current_position'] ?? null),
            'ordination_date'            => $this->clean($raw['ordination_date'] ?? null),
            'concurrent_position'        => $this->clean($raw['concurrent_position'] ?? null),
            'activity_scope'             => $this->parseSelectedOptionsText($raw['activity_scope'] ?? ''),
            'notes'                      => $this->clean($raw['notes'] ?? null),
            'education_level'            => $this->clean($raw['education_level'] ?? null),
            'professional_qualification' => $this->clean($raw['professional_qualification'] ?? null),
            'religious_education_level'  => $this->clean($raw['religious_education_level'] ?? null),
            'training_institutions'      => $this->clean($raw['training_institutions'] ?? null),
            'languages'                  => $this->clean($raw['languages'] ?? null),
            'activity_history'           => $this->buildActivityHistory($raw),
            'commendation_discipline'    => $this->clean($raw['commendation_discipline'] ?? null),
            'violations'                 => $this->clean($raw['violations'] ?? null),
            'congress_term'              => $this->clean($raw['congress_term'] ?? null),
            'phone'                      => $this->clean($raw['phone'] ?? null),
            'email'                      => $this->clean($raw['email'] ?? null),
            'status'                     => $this->parseSelectedOptionsText($raw['status'] ?? ''),
        ];

        [$templeName, $provinceName] = $this->splitTempleAndProvince($data['current_address'] ?? '');
        $data['temple_name'] = $templeName;
        $data['province_name'] = $provinceName;

        return $data;
    }

    /**
     * @return array<string, array{start: int, valueStart: int}>
     */
    private function locateLabels(string $text): array
    {
        $positions = [];

        foreach (self::FIELD_LABELS as $key => $label) {
            // preg_quote($label, '/') tự escape MỌI ký tự đặc biệt trong nhãn (kể cả
            // "/" xuất hiện tự nhiên trong tiếng Việt như "Hệ phái/Dòng tu" — nếu
            // không escape, "/" phá vỡ luôn delimiter "/" của chính regex, khiến
            // preg_match() lỗi "Unknown modifier" âm thầm và bỏ sót field đó).
            //
            // CỐ Ý không match số thứ tự đầu mục (vd "2.") — text docx nối liền không
            // khoảng trắng giữa các field, nên nếu field TRƯỚC đó có giá trị kết thúc
            // bằng số (vd "12/12") đứng sát số thứ tự mục kế tiếp ("12/122. *Trình độ
            // ..."), việc match "\d+\." sẽ "ăn nhầm" luôn chữ số cuối "2" của giá trị
            // "12/12" thành số thứ tự — đã tái hiện thực tế bug này. Để số thứ tự lẫn
            // vào cuối giá trị field trước rồi dọn bằng clean() còn an toàn hơn nhiều.
            $pattern = '/\*?\s*'.preg_quote($label, '/').'/u';

            if (! preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $labelEnd = $m[0][1] + strlen($m[0][0]);

            // Nhiều nhãn có phần mô tả/ví dụ trong ngoặc TRƯỚC dấu ":" thật (vd
            // "Trình độ học vấn phổ thông (ví dụ: 12/12):") — TỰ BẢN THÂN phần mô tả
            // đó cũng hay chứa dấu ":" (vd chính chữ "ví dụ:"), nên không thể chỉ tìm
            // dấu ":" gần nhất — phải bỏ qua mọi dấu ":" nằm trong ngoặc, chỉ nhận dấu
            // ":" đầu tiên xuất hiện NGOÀI ngoặc (xem findValueColon()).
            $colonPos = $this->findValueColon($text, $labelEnd);

            if ($colonPos === null) {
                continue;
            }

            // Offset của PREG_OFFSET_CAPTURE/strpos là BYTE offset (không phải ký tự)
            // — dùng substr() (byte-based) để cắt ở sliceValues(), KHÔNG dùng
            // mb_substr(), để tránh lệch vị trí với chuỗi UTF-8 nhiều byte.
            $positions[$key] = [
                'start'      => $m[0][1],
                'valueStart' => $colonPos + 1,
            ];
        }

        // Tiêu đề mục không có dấu ":" — chỉ cần vị trí bắt đầu để làm ranh giới, xem
        // SECTION_HEADERS. "valueStart" không dùng tới (không field nào tham chiếu
        // key "_section*"), gán bằng "start" cho hợp lệ kiểu dữ liệu.
        foreach (self::SECTION_HEADERS as $i => $header) {
            $pattern = '/'.preg_quote($header, '/').'/u';

            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                $positions["_section{$i}"] = ['start' => $m[0][1], 'valueStart' => $m[0][1]];
            }
        }

        return $positions;
    }

    /**
     * Quét từ $from tìm dấu ":" đầu tiên nằm NGOÀI mọi cặp ngoặc () — vd với
     * "(ví dụ: 12/12): 12/12", dấu ":" sau "ví dụ" bị bỏ qua vì đang ở độ sâu ngoặc
     * 1, chỉ nhận dấu ":" thứ 2 (sau khi ngoặc đã đóng, độ sâu về 0).
     */
    private function findValueColon(string $text, int $from): ?int
    {
        $depth = 0;
        $len = strlen($text);

        for ($i = $from; $i < $len; $i++) {
            $char = $text[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ':' && $depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{start: int, valueStart: int}>  $positions
     * @return array<string, string>
     */
    private function sliceValues(string $text, array $positions): array
    {
        $ordered = $positions;
        uasort($ordered, fn ($a, $b) => $a['start'] <=> $b['start']);
        $keys = array_keys($ordered);
        $values = [];

        foreach ($keys as $i => $key) {
            $start = $ordered[$key]['valueStart'];
            $end = isset($keys[$i + 1]) ? $ordered[$keys[$i + 1]]['start'] : strlen($text);
            $values[$key] = substr($text, $start, max(0, $end - $start));
        }

        return $values;
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        // Số thứ tự mục kế tiếp (vd "2.") hay dính vào cuối giá trị field này do
        // locateLabels() cố ý không match số thứ tự (xem lý do ở đó) — dọn ở đây an
        // toàn hơn nhiều so với cố match số thứ tự lúc định vị nhãn. CHỈ 1 chữ số
        // (không phải 1-2) — toàn bộ số thứ tự mục trong phiếu này đều từ 1-9 (số có
        // 2 chữ số trở lên không xuất hiện); nếu cho phép 2 chữ số, regex có thể "ăn
        // nhầm" 2 chữ số CUỐI của 1 giá trị hợp lệ kết thúc bằng số dính liền số thứ
        // tự mục kế tiếp (đã tái hiện thực tế: "12/12" + "2." dính liền bị hiểu nhầm
        // thành "12/1" + "22." thay vì đúng "12/12" + "2.").
        $value = preg_replace('/\d\.\s*$/u', '', $value) ?? $value;

        // Field bỏ trống trên phiếu thường để lại dấu chấm lửng "...." hoặc chỉ
        // khoảng trắng/xuống dòng — coi như null thay vì lưu chuỗi rác.
        $value = trim(trim($value), ". \t\n\r\0\x0B");

        return $value === '' ? null : $value;
    }

    /**
     * clarifyCheckboxes() (xem MonasticImportService) đã chèn "[ĐÃ_CHỌN]"/"[chưa_chọn]"
     * ngay TRƯỚC mỗi lựa chọn checkbox — text sau 1 nhãn tới nhãn kế tiếp CHÍNH LÀ toàn
     * bộ nội dung lựa chọn đó (kể cả phần "(ghi rõ): ..." nếu có), nên dùng chung được
     * cho cả 3 nhóm checkbox (Phân loại/Phạm vi hoạt động/Tình trạng hiện tại) — không
     * cần viết riêng 3 hàm.
     *
     * @return array<int, string> danh sách nguyên văn các lựa chọn ĐÃ chọn
     */
    private function selectedOptions(string $section): array
    {
        preg_match_all('/\[ĐÃ_CHỌN\]\s*([^\[]+?)(?=\[(?:ĐÃ_CHỌN|chưa_chọn)\]|$)/u', $section, $matches);

        return array_values(array_filter(array_map(fn ($v) => $this->clean($v) ?? '', $matches[1] ?? [])));
    }

    private function parseClassification(string $section): array
    {
        $map = ['Chức sắc' => 'chuc_sac', 'Chức việc' => 'chuc_viec', 'Nhà tu hành' => 'nha_tu_hanh'];
        $result = [];

        foreach ($this->selectedOptions($section) as $selected) {
            foreach ($map as $label => $key) {
                if (mb_stripos($selected, $label) === 0) {
                    $result[] = $key;

                    break;
                }
            }
        }

        return $result;
    }

    private function parseSelectedOptionsText(string $section): ?string
    {
        $selected = $this->selectedOptions($section);

        return $selected === [] ? null : implode('; ', $selected);
    }

    /**
     * @param  array<string, string>  $raw
     */
    private function buildActivityHistory(array $raw): ?string
    {
        $parts = array_filter([
            $this->clean($raw['_activity_period'] ?? null),
            $this->clean($raw['_activity_place'] ?? null),
            $this->clean($raw['_activity_position'] ?? null),
        ]);

        return $parts === [] ? null : implode('; ', $parts);
    }

    /**
     * "Nơi ở hiện tại" ghi tên tự viện + địa chỉ trên cùng 1 dòng (vd "Chùa Phật
     * Quang, số 83 Quang Trung, phường Rạch Giá, tỉnh An Giang") — tách phần tên tự
     * viện (đoạn trước dấu phẩy đầu tiên) và tìm tên tỉnh khớp trong toàn bộ chuỗi
     * (kể cả alias), cùng nguyên tắc với TempleSearchService::extractTrailingProvince().
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitTempleAndProvince(string $currentAddress): array
    {
        if ($currentAddress === '') {
            return [null, null];
        }

        $provinceName = null;

        foreach (Province::all() as $province) {
            foreach (array_merge([$province->name], $province->aliases ?? []) as $alias) {
                if (mb_stripos($currentAddress, $alias) !== false) {
                    $provinceName = $province->name;

                    break 2;
                }
            }
        }

        $templeName = $this->clean(explode(',', $currentAddress)[0] ?? null);

        return [$templeName, $provinceName];
    }
}

<?php

namespace Tests\Feature;

use App\Models\Province;
use App\Services\MonasticFormParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonasticFormParserServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nguyên văn text trích thật (docx, đã qua clarifyCheckboxes()) từ file mẫu
     * "01. THÍCH MINH NHẪN - CHÙA PHẬT QUANG.docx" — dùng làm fixture hồi quy vì đây
     * chính là file đã dùng làm ground-truth xuyên suốt việc kiểm chứng Gemini trước
     * đó (full_name đúng phải là "TỪ THÀNH ĐẠT", không phải "TÌ VÀO THÀNH ĐẠT" như
     * Gemini từng đọc nhầm 1 lần).
     */
    private const SAMPLE_TEXT = <<<'TEXT'
PHIẾU THU THẬP THÔNG TIN DỮ LIỆU VỀ CHỨC SẮC, CHỨC VIỆC, NHÀ TU HÀNH TÔN GIÁO(PHIẾU SỐ 3)I. THÔNG TIN ĐỊNH DANH VÀ CÁ NHÂN CƠ BẢN*Ảnh chân dung (4x6): *Họ và tên khai sinh: TỪ THÀNH ĐẠT*Tên trong tôn giáo (Tên gọi chính thức được sử dụng trong tôn giáo): THÍCH MINH NHẪN*Ngày, tháng, năm sinh: 16/08/1972*Giới tính: Nam*Dân tộc: Kinh* Quốc tịch: Việt Nam*Số Căn cước công dân (CCCD): 091072002802Ngày cấp: 05/06/2022         Nơi cấp: Cục CSQLHC Về TTXH*Quê quán (Theo thông tin trên VneID): Phường Rạch Giá, tỉnh An Giang*Địa chỉ thường trú (Theo thông tin trên VneID): Xã Kiên Lương, tỉnh An Giang*Nơi ở hiện tại: Chùa Phật Quang, số 83 Quang Trung, phường Rạch Giá, tỉnh An GiangSố chứng nhận Tăng ni: 42801/CNTN.2023 Ngày tháng cấp: 04/10/2023II. NHÓM THÔNG TIN HÀNH ĐẠO VÀ CHUYÊN MÔN TÔN GIÁO*Tôn giáo: Phật giáo*Tổ chức tôn giáo (Tên tổ chức mà cá nhân là thành viên): Giáo hội Phật giáo Việt Nam*Hệ phái/Dòng tu (Thuộc hệ phái/dòng tu nào): Bắc tông*Phân loại (có thể vừa là chức sắc, vừa là chức việc): [ĐÃ_CHỌN]  Chức sắc[ĐÃ_CHỌN]  Chức việc[chưa_chọn]  Nhà tu hành5. *Chức vụ/Phẩm vị hiện tại (Liệt kê các chức vụ đang đảm nhiệm): - ỦY VIÊN THƯ KÝ HĐTS- PHÓ CHÁNH VĂN PHÒNG 2 TRUNG ƯƠNG GHPGVN- PHÓ TRƯỞNG BAN THƯỜNG TRỰC BAN TRỊ SỰ GHPGVN TỈNH AN GIANG- PHẨM VỊ: THƯỢNG TỌA6. *Ngày thụ phong/bổ nhiệm (Mốc thời gian chính thức công nhận chức vụ/phẩm vị): ...............................................................................................................................7. *Chức vụ kiêm nhiệm (nếu có): ...........................................................................8. *Phạm vi hoạt động: [ĐÃ_CHỌN]  Toàn quốc[chưa_chọn]  Một số tỉnh, thành phố (ghi rõ) .........................................................................…[ĐÃ_CHỌN] Trong địa bàn một tỉnh/thành phố (ghi rõ): tỉnh An Giang9. *Ghi chú: .............................................................................................................III. NHÓM THÔNG TIN VỀ QUÁ TRÌNH ĐÀO TẠO1. *Trình độ học vấn phổ thông (ví dụ: 12/12): 12/122. *Trình độ chuyên môn (nếu có, ví dụ: Cử nhân Luật, Bác sĩ, …): Tiến sĩ3. *Trình độ tu học (ví dụ: Tiến sĩ Phật học, Thượng thừa, …): 4. *Cơ sở đào tạo tôn giáo đã theo học (Danh sách các trường, học viện đã học; ví dụ: Học viện Phật giáo Việt Nam tại Thành phố Hồ Chí Minh, …): 5. *Ngoại ngữ/Tiếng dân tộc khác: IV. NHÓM THÔNG TIN VỀ QUÁ TRÌNH HOẠT ĐỘNG VÀ BỔ NHIỆM/BẦU CỬ/SUY CỬ1. *Từ ngày – Đến ngày (Chức vụ và khoảng thời gian đảm nhận): ..............................................................................................................................2. *Nơi hành đạo/hoạt động (Tên cơ sở thờ tự, tổ chức tôn giáo): - Chùa Phật Quang, số 83 Quang Trung, phường Rạch Giá, tỉnh An Giang - GHPGVN tỉnh An Giang3. *Chức vụ đảm nhận (Chức vụ cụ thể tại nơi công tác trong giai đoạn đó): - ỦY VIÊN THƯ KÝ HĐTS- PHÓ CHÁNH VĂN PHÒNG 2 TRUNG ƯƠNG GHPGVN- PHÓ TRƯỞNG BAN THƯỜNG TRỰC BAN TRỊ SỰ GHPGVN TỈNH AN GIANG4. *Khen thưởng/kỷ luật (nếu có): 5. *Các khiếu kiện, vi phạm pháp luật (nếu có):.......................................................................................................................................6. *Nhiệm kỳ đại hội từ năm – đến năm: ........................................................................................................................................V. NHÓM THÔNG TIN LIÊN HỆ VÀ TÌNH TRẠNG1. *Số điện thoại: 09827606242. *Email (nếu có): ......................................................................................................3. *Tình trạng hiện tại:  - Chức sắc/Chức việc[ĐÃ_CHỌN]  Đang hoạt động[chưa_chọn]  Hưu trí, an dưỡng, dưỡng nhàn[chưa_chọn]  Cách chức/bãi nhiệm[chưa_chọn]  Đã chết (Ghi rõ ngày mất): .........................................................................- Nhà tu hành[ĐÃ_CHỌN]  Đang tu hành[chưa_chọn]  Hoàn tục[chưa_chọn]  Đã chết (Ghi rõ ngày mất): .........................................................................[chưa_chọn]  Tẩn xuấtVII. TÀI LIỆU ĐÍNH KÈM[chưa_chọn]  File scan số hóa văn bản bầu cử / suy cử / bổ nhiệm [chưa_chọn]  File scan số hoá văn bản quyết định thuyên chuyển, bãi nhiệm.[chưa_chọn]  File scan số hoá quyết định khen thưởng/kỷ luật.[chưa_chọn]  Phiếu lý lịch tư pháp.- Loại giấy tờ tùy thân gửi kèm:[chưa_chọn] CMND                  [ĐÃ_CHỌN] CCCD                     [chưa_chọn] Hộ chiếu            [ĐÃ_CHỌN]  CNTNAn Giang, ngày 15 tháng 12 năm 2025Xác nhận của người được thu thập dữ liệu/cơ quan quản lý (Ban Trị sự tỉnh)Người kê khai(Ký, ghi rõ họ tên)
TEXT;

    protected function setUp(): void
    {
        parent::setUp();
        Province::create(['name' => 'An Giang', 'aliases' => []]);
    }

    public function test_parses_all_fields_correctly_from_real_sample(): void
    {
        $data = app(MonasticFormParserService::class)->parse(self::SAMPLE_TEXT);

        $this->assertNotNull($data);
        $this->assertSame('TỪ THÀNH ĐẠT', $data['full_name']);
        $this->assertSame('THÍCH MINH NHẪN', $data['religious_name']);
        $this->assertSame('16/08/1972', $data['birth_date']);
        $this->assertSame('Nam', $data['gender']);
        $this->assertSame('Kinh', $data['ethnicity']);
        $this->assertSame('Việt Nam', $data['nationality']);
        $this->assertSame('091072002802', $data['id_number']);
        $this->assertSame('05/06/2022', $data['id_issued_date']);
        $this->assertSame('Cục CSQLHC Về TTXH', $data['id_issued_place']);
        $this->assertSame('Phường Rạch Giá, tỉnh An Giang', $data['hometown']);
        $this->assertSame('Xã Kiên Lương, tỉnh An Giang', $data['permanent_address']);
        $this->assertSame('Chùa Phật Quang, số 83 Quang Trung, phường Rạch Giá, tỉnh An Giang', $data['current_address']);
        $this->assertSame('42801/CNTN.2023', $data['monastic_cert_number']);
        $this->assertSame('04/10/2023', $data['monastic_cert_date']);
        $this->assertSame('Phật giáo', $data['religion']);
        $this->assertSame('Giáo hội Phật giáo Việt Nam', $data['religious_org']);
        $this->assertSame('Bắc tông', $data['sect']);
        $this->assertSame(['chuc_sac', 'chuc_viec'], $data['classification']);
        $this->assertStringContainsString('ỦY VIÊN THƯ KÝ HĐTS', $data['current_position']);
        $this->assertNull($data['ordination_date']);
        $this->assertNull($data['concurrent_position']);
        $this->assertStringContainsString('Toàn quốc', $data['activity_scope']);
        $this->assertStringContainsString('tỉnh An Giang', $data['activity_scope']);
        $this->assertNull($data['notes']);
        $this->assertSame('12/12', $data['education_level']);
        $this->assertSame('Tiến sĩ', $data['professional_qualification']);
        $this->assertNull($data['religious_education_level']);
        $this->assertNull($data['training_institutions']);
        $this->assertNull($data['languages']);
        $this->assertNull($data['commendation_discipline']);
        $this->assertNull($data['violations']);
        $this->assertNull($data['congress_term']);
        $this->assertSame('0982760624', $data['phone']);
        $this->assertNull($data['email']);
        $this->assertSame('Đang hoạt động; Đang tu hành', $data['status']);
        $this->assertSame('Chùa Phật Quang', $data['temple_name']);
        $this->assertSame('An Giang', $data['province_name']);
    }

    public function test_returns_null_for_unrecognized_text(): void
    {
        $data = app(MonasticFormParserService::class)->parse('Đây là 1 đoạn văn bản bất kỳ, không phải mẫu phiếu số 3.');

        $this->assertNull($data);
    }

    /**
     * Đã tái hiện lỗi thật: 1 số file dùng dấu ba chấm Unicode "……" (U+2026) lặp lại
     * làm placeholder ô trống thay vì dấu chấm "." thường — trim() cũ không strip
     * được (khác byte hẳn), khiến field trống bị lưu nguyên chuỗi "……………" rác thay vì
     * null. Field "Từ ngày – Đến ngày" cũng bị lẫn số thứ tự "12." của field liền sau
     * do 1 số file đánh số liên tục 2 chữ số (không chỉ 1-9 như file mẫu gốc).
     */
    public function test_ellipsis_placeholder_and_multi_digit_numbering_are_cleaned(): void
    {
        $text = str_replace(
            ['Kinh', '16/08/1972'],
            ["\u{2026}\u{2026}\u{2026}\u{2026}\u{2026}\u{2026}\u{2026}\u{2026}\u{2026}\u{2026}", '……………'],
            self::SAMPLE_TEXT
        );

        $data = app(MonasticFormParserService::class)->parse($text);

        $this->assertNotNull($data);
        $this->assertNull($data['ethnicity']);
        $this->assertNull($data['birth_date']);
    }
}

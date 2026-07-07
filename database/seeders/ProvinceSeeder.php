<?php

namespace Database\Seeders;

use App\Models\Province;
use Illuminate\Database\Seeder;

class ProvinceSeeder extends Seeder
{
    /**
     * 34 tỉnh/thành sau sáp nhập hành chính có hiệu lực từ 1/7/2025 (Nghị quyết
     * kỳ họp thứ 9, Quốc hội khoá XV). "aliases" là tên tỉnh/thành cũ đã hợp
     * nhất vào, dùng để AI đối chiếu khi tài liệu còn ghi theo địa danh cũ.
     */
    public function run(): void
    {
        $provinces = [
            // 11 tỉnh/thành không sáp nhập
            ['name' => 'Cao Bằng', 'aliases' => []],
            ['name' => 'Điện Biên', 'aliases' => []],
            ['name' => 'Hà Tĩnh', 'aliases' => []],
            ['name' => 'Lai Châu', 'aliases' => []],
            ['name' => 'Lạng Sơn', 'aliases' => []],
            ['name' => 'Nghệ An', 'aliases' => []],
            ['name' => 'Quảng Ninh', 'aliases' => []],
            ['name' => 'Thanh Hóa', 'aliases' => []],
            ['name' => 'Sơn La', 'aliases' => []],
            ['name' => 'Hà Nội', 'aliases' => []],
            ['name' => 'Huế', 'aliases' => ['Thừa Thiên Huế']],

            // 23 tỉnh/thành hình thành sau sáp nhập
            ['name' => 'Tuyên Quang', 'aliases' => ['Hà Giang']],
            ['name' => 'Lào Cai', 'aliases' => ['Yên Bái']],
            ['name' => 'Thái Nguyên', 'aliases' => ['Bắc Kạn']],
            ['name' => 'Phú Thọ', 'aliases' => ['Vĩnh Phúc', 'Hòa Bình']],
            ['name' => 'Bắc Ninh', 'aliases' => ['Bắc Giang']],
            ['name' => 'Hưng Yên', 'aliases' => ['Thái Bình']],
            ['name' => 'Hải Phòng', 'aliases' => ['Hải Dương']],
            ['name' => 'Ninh Bình', 'aliases' => ['Hà Nam', 'Nam Định']],
            ['name' => 'Quảng Trị', 'aliases' => ['Quảng Bình']],
            ['name' => 'Đà Nẵng', 'aliases' => ['Quảng Nam']],
            ['name' => 'Quảng Ngãi', 'aliases' => ['Kon Tum']],
            ['name' => 'Gia Lai', 'aliases' => ['Bình Định']],
            ['name' => 'Khánh Hòa', 'aliases' => ['Ninh Thuận']],
            ['name' => 'Lâm Đồng', 'aliases' => ['Đắk Nông', 'Bình Thuận']],
            ['name' => 'Đắk Lắk', 'aliases' => ['Phú Yên']],
            ['name' => 'TP. Hồ Chí Minh', 'aliases' => ['Hồ Chí Minh', 'Sài Gòn', 'Bà Rịa - Vũng Tàu', 'Bình Dương']],
            ['name' => 'Đồng Nai', 'aliases' => ['Bình Phước']],
            ['name' => 'Tây Ninh', 'aliases' => ['Long An']],
            ['name' => 'Cần Thơ', 'aliases' => ['Sóc Trăng', 'Hậu Giang']],
            ['name' => 'Vĩnh Long', 'aliases' => ['Bến Tre', 'Trà Vinh']],
            ['name' => 'Đồng Tháp', 'aliases' => ['Tiền Giang']],
            ['name' => 'Cà Mau', 'aliases' => ['Bạc Liêu']],
            ['name' => 'An Giang', 'aliases' => ['Kiên Giang']],
        ];

        foreach ($provinces as $province) {
            Province::updateOrCreate(
                ['name' => $province['name']],
                ['aliases' => $province['aliases']]
            );
        }
    }
}

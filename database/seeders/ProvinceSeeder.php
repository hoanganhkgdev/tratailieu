<?php

namespace Database\Seeders;

use App\Models\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProvinceSeeder extends Seeder
{
    /**
     * Danh sách chính thức 34 tỉnh/thành sau sáp nhập (hiệu lực 12/06/2025 - 01/07/2025)
     * theo Nghị quyết 202/2025/QH15. Mỗi tỉnh mới lưu kèm "aliases" là tên các tỉnh cũ
     * đã hợp nhất vào, để SmartImport đối chiếu khi tài liệu ghi địa danh trước sáp nhập.
     */
    public function run(): void
    {
        $provinces = [
            // ===== Miền Bắc =====
            ['name' => 'Hà Nội', 'region' => 'Miền Bắc', 'aliases' => []],
            ['name' => 'Cao Bằng', 'region' => 'Miền Bắc', 'aliases' => []],
            ['name' => 'Điện Biên', 'region' => 'Miền Bắc', 'aliases' => []],
            ['name' => 'Lai Châu', 'region' => 'Miền Bắc', 'aliases' => []],
            ['name' => 'Lạng Sơn', 'region' => 'Miền Bắc', 'aliases' => []],
            ['name' => 'Quảng Ninh', 'region' => 'Miền Bắc', 'aliases' => []],
            ['name' => 'Sơn La', 'region' => 'Miền Bắc', 'aliases' => []],
            ['name' => 'Tuyên Quang', 'region' => 'Miền Bắc', 'aliases' => ['Hà Giang']],
            ['name' => 'Lào Cai', 'region' => 'Miền Bắc', 'aliases' => ['Yên Bái']],
            ['name' => 'Thái Nguyên', 'region' => 'Miền Bắc', 'aliases' => ['Bắc Kạn']],
            ['name' => 'Phú Thọ', 'region' => 'Miền Bắc', 'aliases' => ['Vĩnh Phúc', 'Hòa Bình']],
            ['name' => 'Bắc Ninh', 'region' => 'Miền Bắc', 'aliases' => ['Bắc Giang']],
            ['name' => 'Hưng Yên', 'region' => 'Miền Bắc', 'aliases' => ['Thái Bình']],
            ['name' => 'Hải Phòng', 'region' => 'Miền Bắc', 'aliases' => ['Hải Dương']],
            ['name' => 'Ninh Bình', 'region' => 'Miền Bắc', 'aliases' => ['Hà Nam', 'Nam Định']],

            // ===== Miền Trung =====
            ['name' => 'Thanh Hóa', 'region' => 'Miền Trung', 'aliases' => []],
            ['name' => 'Nghệ An', 'region' => 'Miền Trung', 'aliases' => []],
            ['name' => 'Hà Tĩnh', 'region' => 'Miền Trung', 'aliases' => []],
            ['name' => 'Huế', 'region' => 'Miền Trung', 'aliases' => ['Thừa Thiên Huế']],
            ['name' => 'Quảng Trị', 'region' => 'Miền Trung', 'aliases' => ['Quảng Bình']],
            ['name' => 'Đà Nẵng', 'region' => 'Miền Trung', 'aliases' => ['Quảng Nam']],
            ['name' => 'Quảng Ngãi', 'region' => 'Miền Trung', 'aliases' => ['Kon Tum']],
            ['name' => 'Gia Lai', 'region' => 'Miền Trung', 'aliases' => ['Bình Định']],
            ['name' => 'Đắk Lắk', 'region' => 'Miền Trung', 'aliases' => ['Phú Yên']],
            ['name' => 'Khánh Hòa', 'region' => 'Miền Trung', 'aliases' => ['Ninh Thuận']],
            ['name' => 'Lâm Đồng', 'region' => 'Miền Trung', 'aliases' => ['Đắk Nông', 'Bình Thuận']],

            // ===== Miền Nam =====
            ['name' => 'TP. Hồ Chí Minh', 'region' => 'Miền Nam', 'aliases' => ['Bà Rịa - Vũng Tàu', 'Bà Rịa Vũng Tàu', 'Bình Dương']],
            ['name' => 'Đồng Nai', 'region' => 'Miền Nam', 'aliases' => ['Bình Phước']],
            ['name' => 'Tây Ninh', 'region' => 'Miền Nam', 'aliases' => ['Long An']],
            ['name' => 'Đồng Tháp', 'region' => 'Miền Nam', 'aliases' => ['Tiền Giang']],
            ['name' => 'Vĩnh Long', 'region' => 'Miền Nam', 'aliases' => ['Bến Tre', 'Trà Vinh']],
            ['name' => 'Cần Thơ', 'region' => 'Miền Nam', 'aliases' => ['Sóc Trăng', 'Hậu Giang']],
            ['name' => 'An Giang', 'region' => 'Miền Nam', 'aliases' => ['Kiên Giang']],
            ['name' => 'Cà Mau', 'region' => 'Miền Nam', 'aliases' => ['Bạc Liêu']],
        ];

        foreach ($provinces as $data) {
            Province::query()->updateOrCreate(
                ['name' => $data['name']],
                [
                    'slug'    => Str::slug($data['name']),
                    'region'  => $data['region'],
                    'aliases' => $data['aliases'],
                ]
            );
        }
    }
}

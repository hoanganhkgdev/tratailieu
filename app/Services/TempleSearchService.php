<?php

namespace App\Services;

use App\Models\Temple;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Meilisearch\Exceptions\ApiException;

class TempleSearchService
{
    /**
     * Meilisearch tự tách từ, chuẩn hoá dấu tiếng Việt, chịu lỗi chính tả và tự
     * xếp hạng theo độ liên quan (ranking rules mặc định: words, typo, proximity,
     * attribute, sort, exactness) — không cần tự viết logic so khớp/chấm điểm
     * bằng tay như bản LIKE cũ.
     */
    public function search(string $query, int $limit = 5): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return new Collection();
        }

        try {
            // Tìm 2 tầng: trước hết CHỈ khớp trên tên tự viện + tên trụ trì (độ chính
            // xác cao). Field "monastics" gộp chung tên của CẢ CHỤC chức sắc trong 1
            // tự viện — nếu tìm luôn cả field này ngay từ đầu, 1 tự viện có nhiều chức
            // sắc cùng họ/pháp danh gần giống câu hỏi (vd nhiều vị cùng bắt đầu "Thích
            // Lệ...") sẽ cộng dồn điểm khớp và lấn át đúng kết quả cần tìm (vd hỏi tên
            // trụ trì cụ thể nhưng ra tự viện khác có DANH SÁCH chức sắc trùng vài từ).
            // Chỉ mở rộng tìm cả monastics/địa chỉ khi tầng 1 không đủ kết quả.
            $primary = Temple::search($query)
                ->options(['attributesToSearchOn' => ['head_monk', 'name']])
                ->query(fn ($builder) => $builder->with(['province', 'monastics', 'latestDocument']))
                ->take($limit)
                ->get();

            if ($primary->count() >= $limit) {
                return $primary;
            }

            $fallback = Temple::search($query)
                ->query(fn ($builder) => $builder->with(['province', 'monastics', 'latestDocument']))
                ->take($limit)
                ->get();

            return $primary->concat($fallback)->unique('id')->take($limit)->values();
        } catch (ApiException $e) {
            // Index chỉ được Meilisearch tự tạo khi có tự viện đầu tiên được lưu —
            // DB rỗng (mới cài, hoặc chưa import gì) thì index chưa tồn tại, coi
            // như chưa có kết quả thay vì làm vỡ trang chat.
            if ($e->httpStatus === 404) {
                Log::warning('Meilisearch index "temples" chưa tồn tại — trả về không có kết quả.');

                return new Collection();
            }

            throw $e;
        }
    }
}

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
            return Temple::search($query)
                ->query(fn ($builder) => $builder->with(['province', 'monastics', 'latestDocument']))
                ->take($limit)
                ->get();
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

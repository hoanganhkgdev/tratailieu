<?php

namespace App\Services;

use App\Models\Temple;
use Illuminate\Support\Collection;

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

        return Temple::search($query)
            ->query(fn ($builder) => $builder->with(['province', 'monastics', 'latestDocument']))
            ->take($limit)
            ->get();
    }
}

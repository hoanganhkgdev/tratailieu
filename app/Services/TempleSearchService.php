<?php

namespace App\Services;

use App\Models\Temple;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Meilisearch\Exceptions\ApiException;

class TempleSearchService
{
    /**
     * Điểm liên quan tối thiểu (thang 0-1 của Meilisearch) để 1 kết quả được coi là
     * "khớp thật" ở tầng tìm chính xác (tên tự viện / tên trụ trì). Hiệu chỉnh bằng
     * dữ liệu thật: khớp đúng luôn ra 0.95-1.0, khớp mờ do typo-tolerance (vd "Trang"
     * tự sửa nhầm thành "Quang"/"Trung") chỉ ra 0.44-0.77 — 0.8 tách rõ 2 nhóm này.
     */
    private const EXACT_TIER_THRESHOLD = 0.8;

    /**
     * Ngưỡng cho tầng 2 (số điện thoại / địa chỉ) — thấp hơn tầng 1 vì đo thực tế:
     * khớp đúng số điện thoại/địa chỉ ra 0.66-0.86, khớp mờ do typo-tolerance của câu
     * KHÔNG tồn tại (như tên người không có thật) chỉ ra ~0.3.
     */
    private const FALLBACK_TIER_THRESHOLD = 0.5;

    /**
     * Chỉ khớp trên 4 field: tên tự viện, tên trụ trì, số điện thoại trụ trì, địa chỉ
     * — KHÔNG tìm theo tên chức sắc/thành viên thường trong chùa.
     */
    private const SEARCHABLE_ATTRIBUTES = ['head_monk', 'name', 'phone', 'address'];

    /**
     * Tìm 2 tầng, dừng ngay khi tầng nào có kết quả — ưu tiên độ CHÍNH XÁC hơn độ
     * "chịu lỗi" của full-text search mặc định, vì người dùng gõ đúng tên 1 chùa/1
     * trụ trì cụ thể luôn mong đợi hoặc ra đúng người đó, hoặc báo không tìm thấy,
     * chứ không muốn thấy danh sách "gần giống" không liên quan.
     *
     * Tầng 1 — tên tự viện / tên trụ trì: bắt buộc khớp ĐỦ mọi từ trong câu hỏi
     * (matchingStrategy=all, mặc định chỉ cần khớp 1 phần) + ngưỡng điểm cao, chỉ tìm
     * trên 2 field này để không bị các field khác làm nhiễu điểm.
     *
     * Tầng 2 — phương án cuối cho số điện thoại/địa chỉ/câu hỏi không tìm đích danh 1
     * chùa: vẫn bắt buộc khớp đủ mọi từ, nhưng ngưỡng thấp hơn tầng 1 vì các field này
     * đa dạng độ dài hơn.
     *
     * Cả 2 tầng đều lọc xác minh lại bằng mb_stripos (PHP, phân biệt dấu chuẩn) sau
     * khi có kết quả từ Meilisearch — bộ tách từ Charabia của Meilisearch tự chuẩn
     * hoá dấu tiếng Việt ở tầng token hoá (độc lập với typoTolerance), khiến 2 tên
     * khác nghĩa hoàn toàn như "Nhân" và "Nhẫn" có thể bị tính là khớp tuyệt đối.
     */
    public function search(string $query, int $limit = 5): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return new Collection();
        }

        try {
            $exact = Temple::search($query)
                ->options([
                    'attributesToSearchOn'  => ['head_monk', 'name'],
                    'matchingStrategy'      => 'all',
                    'rankingScoreThreshold' => self::EXACT_TIER_THRESHOLD,
                ])
                ->query(fn ($builder) => $builder->with(['province', 'monastics', 'latestDocument']))
                ->take($limit)
                ->get()
                ->filter(fn (Temple $t) => $this->containsExact($t->head_monk, $query) || $this->containsExact($t->name, $query))
                ->values();

            if ($exact->isNotEmpty()) {
                return $exact;
            }

            return Temple::search($query)
                ->options([
                    'attributesToSearchOn'  => self::SEARCHABLE_ATTRIBUTES,
                    'matchingStrategy'      => 'all',
                    'rankingScoreThreshold' => self::FALLBACK_TIER_THRESHOLD,
                ])
                ->query(fn ($builder) => $builder->with(['province', 'monastics', 'latestDocument']))
                ->take($limit)
                ->get()
                ->filter(fn (Temple $t) => $this->containsExact($t->head_monk, $query)
                    || $this->containsExact($t->name, $query)
                    || $this->containsExact($t->phone, $query)
                    || $this->containsExact($t->address, $query))
                ->values();
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

    private function containsExact(?string $haystack, string $needle): bool
    {
        return $haystack !== null && mb_stripos($haystack, $needle) !== false;
    }
}

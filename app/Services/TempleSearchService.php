<?php

namespace App\Services;

use App\Models\Monastic;
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
     * Tìm 3 tầng, dừng ngay khi tầng nào có kết quả — ưu tiên độ CHÍNH XÁC hơn độ
     * "chịu lỗi" của full-text search mặc định, vì người dùng gõ đúng tên 1 người/1
     * chùa cụ thể luôn mong đợi hoặc ra đúng người đó, hoặc báo không tìm thấy, chứ
     * không muốn thấy danh sách "gần giống" không liên quan.
     *
     * Tầng 1 — tên tự viện / tên trụ trì: Meilisearch, bắt buộc khớp ĐỦ mọi từ trong
     * câu hỏi (matchingStrategy=all, mặc định chỉ cần khớp 1 phần) + ngưỡng điểm cao,
     * chỉ tìm trên 2 field này (attributesToSearchOn) để không bị field "monastics"
     * (chuỗi nối tên CẢ CHỤC chức sắc) làm nhiễu điểm.
     *
     * Tầng 2 — tên 1 chức sắc thường (không phải trụ trì): field "monastics" là 1
     * chuỗi dài nối tên nhiều người, tên các người khác nhau nằm rải rác nên dù dùng
     * matchingStrategy=all, điểm của 1 khớp ĐÚNG cũng chỉ ngang điểm của nhiều khớp
     * SAI (đã kiểm chứng thực tế: cùng dao động quanh 0.3, không tách được bằng
     * threshold). Nên tầng này bỏ Meilisearch, tra thẳng bảng monastics bằng LIKE
     * khớp chính xác chuỗi con — không chịu lỗi chính tả, nhưng không còn nhiễu.
     *
     * Tầng 3 — phương án cuối, giữ hành vi full-text mặc định (chịu lỗi chính tả,
     * khớp địa chỉ/số điện thoại/khớp 1 phần câu hỏi) cho các câu hỏi không phải tìm
     * đích danh 1 người/1 chùa.
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
                ->get();

            if ($exact->isNotEmpty()) {
                return $exact;
            }

            $byMonastic = $this->searchByMonasticName($query, $limit);

            if ($byMonastic->isNotEmpty()) {
                return $byMonastic;
            }

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

    private function searchByMonasticName(string $query, int $limit): Collection
    {
        $templeIds = Monastic::query()
            ->where(function ($w) use ($query) {
                $w->where('full_name', 'LIKE', '%'.$query.'%')
                    ->orWhere('religious_name', 'LIKE', '%'.$query.'%');
            })
            ->pluck('temple_id')
            ->unique()
            ->take($limit);

        if ($templeIds->isEmpty()) {
            return new Collection();
        }

        return Temple::whereIn('id', $templeIds)
            ->with(['province', 'monastics', 'latestDocument'])
            ->get();
    }
}

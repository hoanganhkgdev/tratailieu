<?php

namespace App\Services;

use App\Models\Monastic;
use App\Models\Temple;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * Ngưỡng cho tầng 3 (toàn field: địa chỉ, số điện thoại, mã...) — thấp hơn tầng 1
     * vì các field này ngắn/đơn nghĩa hơn "monastics" nhưng không tuyệt đối như tên
     * riêng, đo thực tế: khớp đúng số điện thoại/địa chỉ ra 0.66-0.86, khớp mờ do
     * typo-tolerance của câu KHÔNG tồn tại (như tên người không có thật) chỉ ra ~0.3.
     */
    private const FALLBACK_TIER_THRESHOLD = 0.5;

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
     * Tầng 3 — phương án cuối cho địa chỉ/số điện thoại/câu hỏi không tìm đích danh 1
     * người/1 chùa: vẫn bắt buộc khớp đủ mọi từ (matchingStrategy=all) trên toàn bộ
     * field, nhưng ngưỡng thấp hơn tầng 1 vì các field này đa dạng độ dài hơn.
     */
    public function search(string $query, int $limit = 5): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return new Collection();
        }

        try {
            // Bản thân Meilisearch (bộ tách từ Charabia) tự CHUẨN HOÁ DẤU tiếng Việt ở
            // tầng token hoá — độc lập với typoTolerance (đã tắt thử, không đổi kết
            // quả) — nên "Nhân" và "Nhẫn" (2 tên khác hẳn nghĩa) bị token hoá giống
            // nhau và chấm điểm y hệt khớp tuyệt đối. Lọc xác minh lại bằng chuỗi con
            // PHP (mb_stripos, phân biệt dấu chuẩn) sau khi có kết quả từ Meilisearch,
            // loại bỏ những "khớp" mà Meilisearch báo nhưng thực ra field không hề
            // chứa đúng chuỗi đã gõ.
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

            $byMonastic = $this->searchByMonasticName($query, $limit);

            if ($byMonastic->isNotEmpty()) {
                return $byMonastic;
            }

            return Temple::search($query)
                ->options([
                    'matchingStrategy'      => 'all',
                    'rankingScoreThreshold' => self::FALLBACK_TIER_THRESHOLD,
                ])
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
        // Collation mặc định của cột (utf8mb4_unicode_ci) coi các nguyên âm khác dấu
        // là tương đương (đã kiểm chứng: 'Thích Minh Nhân' LIKE '%Nhẫn%' → true dù 2
        // tên khác nghĩa hoàn toàn) — ép COLLATE utf8mb4_0900_as_ci (phân biệt dấu,
        // không phân biệt hoa/thường) chỉ khi chạy MySQL thật; SQLite (test) không hỗ
        // trợ collation này nên giữ LIKE thường.
        $isMysql = DB::getDriverName() === 'mysql';

        $templeIds = Monastic::query()
            ->where(function ($w) use ($query, $isMysql) {
                if ($isMysql) {
                    $w->whereRaw('full_name COLLATE utf8mb4_0900_as_ci LIKE ?', ['%'.$query.'%'])
                        ->orWhereRaw('religious_name COLLATE utf8mb4_0900_as_ci LIKE ?', ['%'.$query.'%']);
                } else {
                    $w->where('full_name', 'LIKE', '%'.$query.'%')
                        ->orWhere('religious_name', 'LIKE', '%'.$query.'%');
                }
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

    private function containsExact(?string $haystack, string $needle): bool
    {
        return $haystack !== null && mb_stripos($haystack, $needle) !== false;
    }
}

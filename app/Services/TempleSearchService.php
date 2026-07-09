<?php

namespace App\Services;

use App\Models\Temple;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Meilisearch\Exceptions\ApiException;

class TempleSearchService
{
    /**
     * Số ứng viên lấy từ Meilisearch trước khi tự lọc lại bằng mb_stripos — phải lớn
     * hơn hẳn $limit vì bộ tách từ của Meilisearch tự chuẩn hoá dấu tiếng Việt (xem
     * containsExact()), nên trong nhóm ứng viên Meilisearch trả về, kết quả ĐÚNG có
     * thể xếp hạng thấp hơn nhiều kết quả "khớp mờ" khác — cần đủ dư để không bỏ sót.
     */
    private const CANDIDATE_POOL_SIZE = 30;

    /**
     * Chỉ khớp trên 5 field: tên tự viện, tên trụ trì, số điện thoại trụ trì, địa chỉ,
     * tỉnh/thành — KHÔNG tìm theo tên chức sắc/thành viên thường trong chùa. Có
     * "province" riêng để gõ kèm tên tỉnh lọc được khi tên chùa trùng ở nhiều nơi
     * (vd "Chùa Phật Quang" có ở 8 tỉnh khác nhau).
     */
    private const SEARCHABLE_ATTRIBUTES = ['head_monk', 'name', 'phone', 'address', 'province'];

    /**
     * Tìm 2 tầng, dừng ngay khi tầng nào có kết quả — ưu tiên độ CHÍNH XÁC hơn độ
     * "chịu lỗi" của full-text search mặc định, vì người dùng gõ đúng tên 1 chùa/1
     * trụ trì cụ thể luôn mong đợi hoặc ra đúng người đó, hoặc báo không tìm thấy,
     * chứ không muốn thấy danh sách "gần giống" không liên quan.
     *
     * KHÔNG dùng rankingScoreThreshold của Meilisearch để lọc độ liên quan — đã thử
     * và phát hiện bộ tách từ Charabia tự chuẩn hoá dấu tiếng Việt ở tầng token hoá
     * (độc lập với typoTolerance, đã tắt thử không đổi kết quả), khiến 2 tên khác
     * nghĩa hoàn toàn như "Nhân"/"Nhẫn" được chấm điểm NGANG NHAU hoặc kết quả SAI lại
     * còn cao hơn kết quả ĐÚNG (vd "Nhân" ra 1.0 trong khi 3 chùa có đúng "Nhẫn" chỉ ra
     * 0.333) — threshold sẽ vô tình loại mất kết quả đúng trước khi kịp xác minh lại.
     * Thay vào đó: lấy dư CANDIDATE_POOL_SIZE ứng viên (chỉ cần matchingStrategy=all
     * để đủ mọi từ có mặt), rồi tự xác minh lại bằng mb_stripos (PHP, phân biệt dấu
     * chuẩn) — chỉ giữ những kết quả field thực sự CHỨA đúng chuỗi đã gõ.
     *
     * Tầng 1 — tên tự viện / tên trụ trì: chỉ tìm trên 2 field này để không bị các
     * field khác làm nhiễu.
     *
     * Tầng 2 — phương án cuối cho số điện thoại/địa chỉ/câu hỏi không tìm đích danh 1
     * chùa: mở rộng tìm cả 4 field.
     */
    public function search(string $query, int $limit = 5): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return new Collection();
        }

        try {
            $exact = $this->searchAndVerify($query, ['head_monk', 'name'], $limit);

            if ($exact->isNotEmpty()) {
                return $exact;
            }

            return $this->searchAndVerify($query, self::SEARCHABLE_ATTRIBUTES, $limit);
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

    /**
     * @param  array<int, string>  $attributes
     */
    private function searchAndVerify(string $query, array $attributes, int $limit): Collection
    {
        return Temple::search($query)
            ->options([
                'attributesToSearchOn' => $attributes,
                'matchingStrategy'     => 'all',
            ])
            ->query(fn ($builder) => $builder->with(['province', 'monastics', 'latestDocument']))
            ->take(self::CANDIDATE_POOL_SIZE)
            ->get()
            ->filter(fn (Temple $t) => $this->containsAllWords($t, $attributes, $query))
            ->take($limit)
            ->values();
    }

    /**
     * Xác minh: MỌI từ trong câu hỏi đều xuất hiện đâu đó trong các field cho phép —
     * không bắt buộc nằm trong CÙNG 1 field, vì câu hỏi có thể ghép tên chùa (field
     * "name") với tên tỉnh (field "province") — mỗi phần khớp ở field riêng của nó,
     * không phải khớp cả cụm liền mạch trong 1 field duy nhất.
     *
     * @param  array<int, string>  $attributes
     */
    private function containsAllWords(Temple $temple, array $attributes, string $query): bool
    {
        $haystack = collect($attributes)
            // "province" là quan hệ (belongsTo), không phải cột string trực tiếp trên
            // Temple — phải lấy $temple->province?->name thay vì $temple->province.
            ->map(fn (string $attr) => $attr === 'province' ? $temple->province?->name : $temple->{$attr})
            ->filter()
            ->implode(' ');

        if ($haystack === '') {
            return false;
        }

        $words = array_filter(preg_split('/\s+/u', $query) ?: []);

        foreach ($words as $word) {
            if (mb_stripos($haystack, $word) === false) {
                return false;
            }
        }

        return true;
    }
}

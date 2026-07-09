<?php

namespace App\Services;

use App\Models\Province;
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
     * KHÔNG dùng rankingScoreThreshold của Meilisearch để lọc độ liên quan — đã thử
     * và phát hiện bộ tách từ Charabia tự chuẩn hoá dấu tiếng Việt ở tầng token hoá
     * (độc lập với typoTolerance, đã tắt thử không đổi kết quả), khiến 2 tên khác
     * nghĩa hoàn toàn như "Nhân"/"Nhẫn" được chấm điểm NGANG NHAU hoặc kết quả SAI lại
     * còn cao hơn kết quả ĐÚNG — threshold sẽ vô tình loại mất kết quả đúng trước khi
     * kịp xác minh lại. Thay vào đó: lấy dư CANDIDATE_POOL_SIZE ứng viên (chỉ cần
     * matchingStrategy=all để đủ mọi từ có mặt), rồi tự xác minh lại bằng mb_stripos
     * (PHP, phân biệt dấu chuẩn) — chỉ giữ kết quả field thực sự CHỨA đúng chuỗi đã gõ
     * NGUYÊN CỤM (không tách rời từng từ rồi khớp rải rác qua nhiều field — từng thử
     * và bị lỗi ngược: "chùa" là từ chung chung xuất hiện ở hầu hết tên tự viện, ghép
     * với 1 trụ trì bất kỳ có tên trùng vài từ khác cũng đủ "khớp đủ mọi từ").
     *
     * Câu hỏi có thể ghép thêm tên tỉnh ở cuối (vd "chùa phật quang an giang" — 1 tên
     * chùa phổ biến có ở hơn chục tỉnh) để lọc chính xác — tách riêng tên tỉnh ra khỏi
     * câu hỏi trước khi tìm, rồi lọc CHÍNH XÁC theo province_id (không suy đoán).
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
            [$coreQuery, $province] = $this->extractTrailingProvince($query);

            $exact = $this->searchAndVerify($coreQuery, ['head_monk', 'name'], $province, $limit);

            if ($exact->isNotEmpty()) {
                return $exact;
            }

            return $this->searchAndVerify($coreQuery, self::SEARCHABLE_ATTRIBUTES, $province, $limit);
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
    private function searchAndVerify(string $query, array $attributes, ?Province $province, int $limit): Collection
    {
        if ($query === '') {
            // Câu hỏi chỉ toàn tên tỉnh (vd chỉ gõ "An Giang") — không có gì để khớp
            // theo tên/trụ trì/địa chỉ, bỏ qua thay vì trả về CẢ tỉnh.
            return new Collection();
        }

        return Temple::search($query)
            ->options([
                'attributesToSearchOn' => $attributes,
                'matchingStrategy'     => 'all',
            ])
            ->query(function ($builder) use ($province) {
                $builder = $builder->with(['province', 'monastics', 'latestDocument']);

                return $province ? $builder->where('province_id', $province->id) : $builder;
            })
            ->take(self::CANDIDATE_POOL_SIZE)
            ->get()
            ->filter(fn (Temple $t) => collect($attributes)->contains(
                fn (string $attr) => $this->containsExact($t->{$attr}, $query)
            ))
            ->take($limit)
            ->values();
    }

    /**
     * Tách tên tỉnh (nếu có) ra khỏi câu hỏi — tìm tên tỉnh/alias xuất hiện trong câu
     * hỏi, cắt bỏ đoạn đó ra khỏi chuỗi, phần còn lại dùng làm câu hỏi "lõi" để khớp
     * tên/trụ trì/địa chỉ như bình thường.
     *
     * @return array{0: string, 1: ?Province}
     */
    private function extractTrailingProvince(string $query): array
    {
        foreach (Province::all() as $province) {
            foreach (array_merge([$province->name], $province->aliases ?? []) as $name) {
                $pos = mb_stripos($query, $name);

                if ($pos === false) {
                    continue;
                }

                $core = trim(mb_substr($query, 0, $pos).' '.mb_substr($query, $pos + mb_strlen($name)));

                return [$core !== '' ? $core : $query, $province];
            }
        }

        return [$query, null];
    }

    private function containsExact(?string $haystack, string $needle): bool
    {
        return $haystack !== null && mb_stripos($haystack, $needle) !== false;
    }
}

<?php

namespace App\Services;

use App\Models\Province;
use App\Models\Temple;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
            // Người dùng copy nguyên 1 dòng trong danh sách gợi ý (định dạng "Tên chùa
            // (Tỉnh) — Trụ trì: X" — xem TempleChatService::formatList()) dán lại để
            // xem chi tiết ngay. Nhận diện đúng định dạng này trước, parse ra 3 phần
            // rồi tra CHÍNH XÁC theo cả 3 (không qua Meilisearch/suy đoán) — đáng tin
            // hơn hẳn vì đây là dữ liệu do chính hệ thống tạo ra, không phải câu hỏi
            // tự do của người dùng.
            $fromListLine = $this->searchFromListLine($query, $limit);

            if ($fromListLine !== null) {
                return $fromListLine;
            }

            [$coreQuery, $province] = $this->extractTrailingProvince($query);

            $exact = $this->searchAndVerify($coreQuery, ['head_monk', 'name'], $province, $limit);

            if ($exact->isNotEmpty()) {
                return $exact;
            }

            $fallback = $this->searchAndVerify($coreQuery, self::SEARCHABLE_ATTRIBUTES, $province, $limit);

            if ($fallback->isNotEmpty()) {
                return $fallback;
            }

            // Tên tỉnh/alias trùng ngẫu nhiên với 1 phần tên người (vd "Quảng Nam" vừa
            // là alias tỉnh Đà Nẵng vừa có thể là 1 phần pháp danh "Thích Quảng Nam")
            // khiến tách nhầm và lọc mất kết quả đúng — nếu tách tỉnh mà không ra gì,
            // thử lại với NGUYÊN câu hỏi (không tách tỉnh) trước khi chịu thua.
            if ($province !== null) {
                $exact = $this->searchAndVerify($query, ['head_monk', 'name'], null, $limit);

                if ($exact->isNotEmpty()) {
                    return $exact;
                }

                return $this->searchAndVerify($query, self::SEARCHABLE_ATTRIBUTES, null, $limit);
            }

            return $fallback;
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
     * Parse dòng dạng "Tên chùa (Tỉnh) — Trụ trì: X" (chấp nhận cả bản markdown thô
     * "**Tên chùa**" nếu người dùng copy nguyên) — không khớp định dạng thì trả về
     * null để search() tiếp tục theo luồng tìm kiếm tự do bình thường.
     */
    private function searchFromListLine(string $query, int $limit): ?Collection
    {
        $clean = str_replace('**', '', $query);

        if (! preg_match('/^(.+?)\s*\(([^)]+)\)\s*[—-]\s*Trụ trì:\s*(.+)$/u', $clean, $m)) {
            return null;
        }

        [, $name, $provinceName, $headMonk] = array_map('trim', $m);
        $province = Province::findByNameOrAlias($provinceName);
        $isMysql = DB::getDriverName() === 'mysql';

        $matches = Temple::query()
            ->when($province, fn ($q) => $q->where('province_id', $province->id))
            ->where(function ($q) use ($name, $isMysql) {
                $isMysql
                    ? $q->whereRaw('name COLLATE utf8mb4_0900_as_ci LIKE ?', ['%'.$name.'%'])
                    : $q->where('name', 'LIKE', '%'.$name.'%');
            })
            ->where(function ($q) use ($headMonk, $isMysql) {
                $isMysql
                    ? $q->whereRaw('head_monk COLLATE utf8mb4_0900_as_ci LIKE ?', ['%'.$headMonk.'%'])
                    : $q->where('head_monk', 'LIKE', '%'.$headMonk.'%');
            })
            ->with(['province', 'monastics', 'latestDocument'])
            ->take($limit)
            ->get();

        return $matches->isNotEmpty() ? $matches->values() : null;
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
            ) || $this->containsSplitAcrossFields($t, $query))
            ->take($limit)
            ->values();
    }

    /**
     * Câu hỏi có thể ghép tên tự viện + tên trụ trì để lọc khi 2 tự viện trùng tên
     * (vd "chùa phật quang thích minh nhẫn" — 2 chùa cùng tên "Phật Quang" ở An Giang,
     * khác trụ trì) — mỗi phần nằm ở field riêng nên containsExact() (đòi khớp nguyên
     * cụm trong 1 field) sẽ không tìm ra. Thử tách câu hỏi tại MỌI vị trí giữa các từ,
     * kiểm tra xem có cách tách nào mà 1 phần khớp "name", phần còn lại khớp
     * "head_monk" hay không (thử cả 2 chiều).
     *
     * Bắt buộc CẢ 2 phần đều phải có từ 2 từ trở lên — nếu không, 1 từ chung chung như
     * "chùa" (có ở hầu hết tên tự viện) một mình cũng đủ "khớp" rồi ghép bừa với phần
     * còn lại thành dương tính giả (đã gặp thật: "chùa từ đàm" suýt khớp nhầm "CHÙA
     * PHÁP HOA" + trụ trì "Thích Từ Đàm" một khi cho phép tách rời từng từ).
     */
    private function containsSplitAcrossFields(Temple $temple, string $query): bool
    {
        $words = array_values(array_filter(preg_split('/\s+/u', $query) ?: []));
        $count = count($words);

        if ($count < 4) {
            return false;
        }

        for ($i = 2; $i <= $count - 2; $i++) {
            $left = implode(' ', array_slice($words, 0, $i));
            $right = implode(' ', array_slice($words, $i));

            if (($this->containsExact($temple->name, $left) && $this->containsExact($temple->head_monk, $right))
                || ($this->containsExact($temple->head_monk, $left) && $this->containsExact($temple->name, $right))) {
                return true;
            }
        }

        return false;
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

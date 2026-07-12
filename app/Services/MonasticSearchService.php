<?php

namespace App\Services;

use App\Models\MonasticProfile;
use App\Models\Province;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Meilisearch\Exceptions\ApiException;

/**
 * Kiến trúc giống hệt TempleSearchService (2 tầng chính xác/fallback + xác minh lại
 * bằng mb_stripos + nhận diện paste dòng danh sách) — chỉ khác field tìm kiếm, xem
 * TempleSearchService để hiểu đầy đủ lý do thiết kế từng bước.
 */
class MonasticSearchService
{
    private const CANDIDATE_POOL_SIZE = 30;

    /**
     * "temple_name" không phải cột thật trên monastic_profiles (chỉ có trong Meilisearch
     * qua toSearchableArray() denormalize từ quan hệ temple) — xem attributeValue().
     */
    private const SEARCHABLE_ATTRIBUTES = ['full_name', 'religious_name', 'phone', 'id_number', 'temple_name'];

    public function search(string $query, int $limit = 5): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return new Collection();
        }

        try {
            $fromListLine = $this->searchFromListLine($query, $limit);

            if ($fromListLine !== null) {
                return $fromListLine;
            }

            [$coreQuery, $province] = $this->extractTrailingProvince($query);

            $exact = $this->searchAndVerify($coreQuery, ['full_name', 'religious_name'], $province, $limit);

            if ($exact->isNotEmpty()) {
                return $exact;
            }

            $fallback = $this->searchAndVerify($coreQuery, self::SEARCHABLE_ATTRIBUTES, $province, $limit);

            if ($fallback->isNotEmpty()) {
                return $fallback;
            }

            if ($province !== null) {
                $exact = $this->searchAndVerify($query, ['full_name', 'religious_name'], null, $limit);

                if ($exact->isNotEmpty()) {
                    return $exact;
                }

                return $this->searchAndVerify($query, self::SEARCHABLE_ATTRIBUTES, null, $limit);
            }

            return $fallback;
        } catch (ApiException $e) {
            if ($e->httpStatus === 404) {
                Log::warning('Meilisearch index "monastic_profiles" chưa tồn tại — trả về không có kết quả.');

                return new Collection();
            }

            throw $e;
        }
    }

    /**
     * Parse dòng dạng "Họ tên (Pháp danh) — Chùa: X, Tỉnh: Y" do chính
     * MonasticChatService::formatList() sinh ra (xem đó để hiểu định dạng chính xác,
     * kể cả giá trị "Chưa rõ" khi thiếu chùa/tỉnh).
     */
    private function searchFromListLine(string $query, int $limit): ?Collection
    {
        $clean = str_replace('**', '', $query);
        // Xoá số thứ tự đầu dòng (vd "1. ") — xem lý do ở TempleSearchService::searchFromListLine(),
        // cùng 1 lỗi thật đã tái hiện được ở đó.
        $clean = preg_replace('/^\d+\.\s*/', '', $clean);

        if (! preg_match('/^(.+?)\s*(?:\(([^)]+)\))?\s*[—-]\s*Chùa:\s*([^,]+),\s*Tỉnh:\s*(.+)$/u', $clean, $m)) {
            return null;
        }

        $fullName = trim($m[1]);
        $religiousName = trim($m[2] ?? '');
        $templeName = trim($m[3]);
        $provinceName = trim($m[4]);

        $province = $provinceName !== 'Chưa rõ' ? Province::findByNameOrAlias($provinceName) : null;
        $isMysql = DB::getDriverName() === 'mysql';

        $matches = MonasticProfile::query()
            ->when($province, fn ($q) => $q->where('province_id', $province->id))
            ->where(function ($q) use ($fullName, $isMysql) {
                $isMysql
                    ? $q->whereRaw('full_name COLLATE utf8mb4_0900_as_ci LIKE ?', ['%'.$fullName.'%'])
                    : $q->where('full_name', 'LIKE', '%'.$fullName.'%');
            })
            ->when($religiousName !== '', fn ($q) => $q->where(function ($q2) use ($religiousName, $isMysql) {
                $isMysql
                    ? $q2->whereRaw('religious_name COLLATE utf8mb4_0900_as_ci LIKE ?', ['%'.$religiousName.'%'])
                    : $q2->where('religious_name', 'LIKE', '%'.$religiousName.'%');
            }))
            ->when($templeName !== 'Chưa rõ', fn ($q) => $q->whereHas('temple', function ($q2) use ($templeName, $isMysql) {
                $isMysql
                    ? $q2->whereRaw('name COLLATE utf8mb4_0900_as_ci LIKE ?', ['%'.$templeName.'%'])
                    : $q2->where('name', 'LIKE', '%'.$templeName.'%');
            }))
            ->with(['temple', 'province', 'document'])
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
            return new Collection();
        }

        return MonasticProfile::search($query)
            ->options([
                'attributesToSearchOn' => $attributes,
                'matchingStrategy'     => 'all',
            ])
            ->query(function ($builder) use ($province) {
                $builder = $builder->with(['temple', 'province', 'document']);

                return $province ? $builder->where('province_id', $province->id) : $builder;
            })
            ->take(self::CANDIDATE_POOL_SIZE)
            ->get()
            ->filter(fn (MonasticProfile $p) => collect($attributes)->contains(
                fn (string $attr) => $this->containsExact($this->attributeValue($p, $attr), $query)
            ) || $this->containsSplitAcrossFields($p, $query))
            ->take($limit)
            ->values();
    }

    /**
     * Câu hỏi ghép "họ tên + tên chùa" (vd trùng pháp danh ở 2 chùa khác nhau) — thử
     * tách tại mọi vị trí giữa các từ, xem có cách tách nào khớp full_name/religious_name
     * ở 1 nửa và temple_name ở nửa còn lại không (thử cả 2 chiều), cùng nguyên tắc với
     * TempleSearchService::containsSplitAcrossFields().
     */
    private function containsSplitAcrossFields(MonasticProfile $profile, string $query): bool
    {
        $words = array_values(array_filter(preg_split('/\s+/u', $query) ?: []));
        $count = count($words);

        if ($count < 4) {
            return false;
        }

        $nameValue = $profile->full_name;
        $templeValue = $profile->temple?->name;

        for ($i = 2; $i <= $count - 2; $i++) {
            $left = implode(' ', array_slice($words, 0, $i));
            $right = implode(' ', array_slice($words, $i));

            if (($this->containsExact($nameValue, $left) && $this->containsExact($templeValue, $right))
                || ($this->containsExact($templeValue, $left) && $this->containsExact($nameValue, $right))) {
                return true;
            }
        }

        return false;
    }

    /**
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

    private function attributeValue(MonasticProfile $profile, string $attr): ?string
    {
        return $attr === 'temple_name' ? $profile->temple?->name : $profile->{$attr};
    }

    private function containsExact(?string $haystack, string $needle): bool
    {
        return $haystack !== null && mb_stripos($haystack, $needle) !== false;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Province extends Model
{
    protected $fillable = ['name', 'slug', 'code', 'region', 'aliases'];

    protected $casts = [
        'aliases' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Province $province) {
            if (empty($province->slug)) {
                $province->slug = Str::slug($province->name);
            }
        });
    }

    public function temples(): HasMany
    {
        return $this->hasMany(Temple::class);
    }

    public function monastics(): HasMany
    {
        return $this->hasMany(Monastic::class);
    }

    /**
     * Tìm tỉnh khớp với tên trích từ tài liệu, đối chiếu cả tên hiện tại lẫn tên cũ
     * trước sáp nhập (aliases). Không tự tạo mới — tránh tạo trùng/sai khi tài liệu
     * ghi địa danh theo địa giới cũ hoặc AI nhận nhầm tên huyện/xã thành tên tỉnh.
     */
    public static function findByNameOrAlias(?string $name): ?self
    {
        if (empty($name)) {
            return null;
        }

        $normalized = static::normalize($name);

        return static::query()->get()->first(function (Province $province) use ($normalized) {
            if (static::normalize($province->name) === $normalized) {
                return true;
            }

            foreach ($province->aliases ?? [] as $alias) {
                if (static::normalize($alias) === $normalized) {
                    return true;
                }
            }

            return false;
        });
    }

    private static function normalize(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/^(tỉnh|thành phố|tp\.?)\s+/u', '', $value);

        return Str::slug($value);
    }
}

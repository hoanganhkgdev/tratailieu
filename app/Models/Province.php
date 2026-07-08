<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Province extends Model
{
    protected $fillable = ['name', 'slug', 'aliases'];

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

    public static function findByNameOrAlias(?string $name): ?self
    {
        if (empty($name)) {
            return null;
        }

        $normalized = self::normalizeForMatch($name);

        return static::query()
            ->get()
            ->first(function (self $province) use ($normalized) {
                if (self::normalizeForMatch($province->name) === $normalized) {
                    return true;
                }

                foreach ($province->aliases ?? [] as $alias) {
                    if (self::normalizeForMatch($alias) === $normalized) {
                        return true;
                    }
                }

                return false;
            });
    }

    /**
     * Chuẩn hoá về Unicode NFC trước khi so khớp — tên thư mục do macOS Finder tạo
     * (vd khi bulk-import từ thư mục local) thường ở dạng NFD (tổ hợp: ký tự gốc +
     * dấu riêng), trong khi tên tỉnh lưu trong DB là NFC (ký tự dựng sẵn có dấu).
     * 2 dạng hiển thị giống hệt nhau nhưng khác byte, so sánh chuỗi thường sẽ luôn
     * trả về false nếu không chuẩn hoá trước — từng làm mọi tỉnh có dấu bị bỏ qua
     * khi chạy temples:bulk-import trên thư mục copy từ Mac.
     */
    private static function normalizeForMatch(string $value): string
    {
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;

        return Str::of($normalized)->lower()->squish()->toString();
    }
}

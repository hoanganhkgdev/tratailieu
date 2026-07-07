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

        $normalized = Str::of($name)->lower()->squish()->toString();

        return static::query()
            ->get()
            ->first(function (self $province) use ($normalized) {
                if (Str::of($province->name)->lower()->squish()->toString() === $normalized) {
                    return true;
                }

                foreach ($province->aliases ?? [] as $alias) {
                    if (Str::of($alias)->lower()->squish()->toString() === $normalized) {
                        return true;
                    }
                }

                return false;
            });
    }
}

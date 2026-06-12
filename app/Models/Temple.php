<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Temple extends Model
{
    protected $fillable = [
        'province_id', 'name', 'slug', 'type', 'address',
        'description', 'phone', 'head_monk', 'established_year',
        'image', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static array $typeLabels = [
        'chua'        => 'Chùa',
        'tu_vien'     => 'Tự viện',
        'tinh_xa'     => 'Tịnh xá',
        'thien_vien'  => 'Thiền viện',
        'tinh_that'   => 'Tịnh thất',
    ];

    protected static function booted(): void
    {
        static::creating(function (Temple $temple) {
            if (empty($temple->slug)) {
                $temple->slug = Str::slug($temple->name);
            }
        });
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function monastics(): HasMany
    {
        return $this->hasMany(Monastic::class);
    }
}

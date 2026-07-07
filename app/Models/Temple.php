<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Temple extends Model
{
    use SoftDeletes;
    use Searchable;

    protected $fillable = [
        'province_id', 'code', 'name', 'slug', 'type', 'address',
        'head_monk', 'phone', 'latest_document_id', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static array $typeLabels = [
        'chua'       => 'Chùa',
        'tu_vien'    => 'Tự viện',
        'tinh_xa'    => 'Tịnh xá',
        'thien_vien' => 'Thiền viện',
        'tinh_that'  => 'Tịnh thất',
    ];

    protected static function booted(): void
    {
        static::creating(function (Temple $temple) {
            if (empty($temple->slug)) {
                $temple->slug = Str::slug($temple->code.'-'.$temple->name);
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

    public function latestDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'latest_document_id');
    }

    protected function makeAllSearchableUsing($query)
    {
        return $query->with(['province', 'monastics']);
    }

    /**
     * Gộp cả tên/pháp danh chức sắc vào đây để tìm được chùa qua tên 1 vị tăng ni,
     * không chỉ qua thông tin của riêng tự viện.
     */
    public function toSearchableArray(): array
    {
        return [
            'id'          => $this->id,
            'code'        => $this->code,
            'name'        => $this->name,
            'type_label'  => self::$typeLabels[$this->type] ?? $this->type,
            'province'    => $this->province?->name,
            'address'     => $this->address,
            'head_monk'   => $this->head_monk,
            'phone'       => $this->phone,
            'monastics'   => $this->monastics
                ->map(fn (Monastic $m) => trim("{$m->full_name} {$m->religious_name} {$m->rank} {$m->position}"))
                ->implode(' | '),
        ];
    }
}

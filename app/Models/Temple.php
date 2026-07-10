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

    /**
     * Hồ sơ cá nhân đầy đủ (phiếu số 3) của tăng ni thuộc tự viện này — khác với
     * monastics() (danh sách rút gọn trích từ hồ sơ CHÙA), đây là dữ liệu trích từ
     * hồ sơ RIÊNG của từng người, đối chiếu qua "nơi hành đạo/nơi ở hiện tại".
     */
    public function monasticProfiles(): HasMany
    {
        return $this->hasMany(MonasticProfile::class);
    }

    public function latestDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'latest_document_id');
    }

    protected function makeAllSearchableUsing($query)
    {
        return $query->with('province');
    }

    /**
     * Chỉ index các field cho phép tìm kiếm: tên tự viện, tên trụ trì, số điện thoại
     * trụ trì, địa chỉ — KHÔNG gộp tên chức sắc/thành viên thường trong chùa vào đây
     * (tìm theo tên 1 người bất kỳ trong danh sách chức sắc dễ gây nhiễu kết quả).
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
        ];
    }
}

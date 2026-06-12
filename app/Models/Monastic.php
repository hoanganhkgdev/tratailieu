<?php

namespace App\Models;

use App\Jobs\ProcessMonasticEmbeddingJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Monastic extends Model
{
    protected $fillable = [
        'temple_id', 'province_id', 'photo',
        // I. Định danh
        'full_name', 'religious_name', 'date_of_birth', 'gender', 'ethnicity', 'nationality',
        'id_type', 'id_number', 'id_issued_date', 'id_issued_place',
        'hometown', 'permanent_address', 'current_address',
        'monastic_cert_number', 'monastic_cert_date',
        // II. Hành đạo
        'religion', 'religious_organization', 'sect', 'classifications', 'rank',
        'current_position', 'appointment_date', 'concurrent_position',
        'activity_scope', 'activity_scope_detail', 'notes',
        // III. Đào tạo
        'education_level', 'professional_qualification', 'buddhist_education_level',
        'training_institutions', 'languages',
        // V. Liên hệ & tình trạng
        'phone', 'email', 'status', 'death_date',
        'embedding',
    ];

    protected $casts = [
        'date_of_birth'        => 'date',
        'id_issued_date'       => 'date',
        'monastic_cert_date'   => 'date',
        'appointment_date'     => 'date',
        'death_date'           => 'date',
        'classifications'      => 'array',
        'embedding'            => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(function (Monastic $monastic) {
            // Tạo lại embedding để AI có thể tra cứu hồ sơ này — chạy nền qua queue,
            // dùng updateQuietly() trong job nên không gây vòng lặp sự kiện saved.
            ProcessMonasticEmbeddingJob::dispatch($monastic);
        });
    }

    public static array $genderLabels = [
        'nam' => 'Nam',
        'nu'  => 'Nữ',
    ];

    public static array $idTypeLabels = [
        'cmnd'                 => 'Chứng minh nhân dân',
        'cccd'                 => 'Căn cước công dân',
        'ho_chieu'             => 'Hộ chiếu',
        'chung_nhan_tang_ni'   => 'Giấy chứng nhận Tăng Ni',
        'khac'                 => 'Khác',
    ];

    public static array $classificationLabels = [
        'chuc_sac'    => 'Chức sắc',
        'chuc_viec'   => 'Chức việc',
        'nha_tu_hanh' => 'Nhà tu hành',
    ];

    public static array $ranksByGender = [
        'nam' => [
            'hoa_thuong' => 'Hòa thượng',
            'thuong_toa' => 'Thượng tọa',
            'dai_duc'    => 'Đại đức',
            'sa_di'      => 'Sa di',
        ],
        'nu' => [
            'ni_truong' => 'Ni trưởng',
            'ni_su'     => 'Ni sư',
            'su_co'     => 'Sư cô',
            'sa_di_ni'  => 'Sa di ni',
        ],
    ];

    public static array $activityScopeLabels = [
        'toan_quoc'   => 'Phạm vi toàn quốc',
        'mot_so_tinh' => 'Phạm vi một số tỉnh/thành phố',
        'mot_tinh'    => 'Phạm vi một tỉnh/thành phố',
    ];

    public static array $statusLabels = [
        'dang_hoat_dong' => 'Đang hoạt động',
        'huu_tri'        => 'Hưu trí / Nghỉ hưu',
        'cach_chuc'      => 'Cách chức / Bãi miễn',
        'hoan_tuc'       => 'Hoàn tục',
        'tan_xuat'       => 'Tản xuất (rời khỏi cơ sở tôn giáo)',
        'da_chet'        => 'Đã qua đời',
    ];

    public static function rankLabel(?string $gender, ?string $rank): ?string
    {
        return static::$ranksByGender[$gender][$rank] ?? $rank;
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(MonasticActivity::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Tổng hợp thông tin hồ sơ thành văn bản để tạo embedding — phục vụ AI tra cứu
     * (giống cách tài liệu được chia nhỏ và embed để tìm theo ngữ nghĩa).
     */
    public function toSearchableText(): string
    {
        $parts = [
            "Họ và tên: {$this->full_name}",
            $this->religious_name ? "Pháp danh: {$this->religious_name}" : null,
            'Giới tính: ' . (static::$genderLabels[$this->gender] ?? $this->gender),
            $this->rank ? 'Phẩm trật: ' . static::rankLabel($this->gender, $this->rank) : null,
            $this->current_position ? "Chức vụ hiện tại: {$this->current_position}" : null,
            $this->concurrent_position ? "Chức vụ kiêm nhiệm: {$this->concurrent_position}" : null,
            $this->temple ? "Tu hành tại: {$this->temple->name}" : null,
            $this->province ? "Địa bàn quản lý: {$this->province->name}" : null,
            $this->sect ? "Hệ phái: {$this->sect}" : null,
            $this->religious_organization ? "Tổ chức tôn giáo: {$this->religious_organization}" : null,
            $this->date_of_birth ? "Năm sinh: {$this->date_of_birth->format('Y')}" : null,
            $this->hometown ? "Quê quán: {$this->hometown}" : null,
            $this->buddhist_education_level ? "Trình độ Phật học: {$this->buddhist_education_level}" : null,
            $this->training_institutions ? "Quá trình đào tạo: {$this->training_institutions}" : null,
            $this->notes ? "Ghi chú: {$this->notes}" : null,
            'Tình trạng: ' . (static::$statusLabels[$this->status] ?? $this->status),
        ];

        foreach ($this->activities as $activity) {
            $period = trim(
                ($activity->from_date?->format('Y') ?? '?') . ' - ' . ($activity->to_date?->format('Y') ?? 'nay')
            );
            $parts[] = trim("Hoạt động giai đoạn {$period}: {$activity->position} tại {$activity->place}");
        }

        return implode('. ', array_filter($parts));
    }
}

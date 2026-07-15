<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class MonasticDocument extends Model
{
    protected $fillable = [
        'temple_id', 'province_id', 'uploaded_by', 'file_path', 'file_name', 'file_type',
        'file_size', 'extracted_json', 'status', 'error_message', 'processed_at',
        'ai_input_tokens', 'ai_output_tokens', 'ai_cost_usd',
    ];

    protected $casts = [
        'extracted_json' => 'array',
        'processed_at'   => 'datetime',
    ];

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(MonasticProfile::class);
    }

    public function getDownloadUrlAttribute(): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $this->file_path)));

        return Storage::disk('public')->url($encoded);
    }

    protected static function booted(): void
    {
        static::deleting(function (MonasticDocument $document) {
            // Xóa document (dù trực tiếp hay dây chuyền từ MonasticProfile::booted())
            // phải dọn theo file gốc trên R2 — không thì rác tồn mãi trên storage,
            // không ai xóa lại được nữa vì mất luôn đường dẫn sau khi record biến mất.
            Storage::disk('public')->delete($document->file_path);

            // monastic_profiles.monastic_document_id có cascadeOnDelete() ở tầng DB —
            // xóa document TRỰC TIẾP (không qua profile) sẽ tự xóa row profile liên
            // quan, nhưng đó là cascade DB thuần, KHÔNG bắn sự kiện Eloquent nên Scout
            // không tự gỡ khỏi Meilisearch được — gọi thẳng unsearchable() ở đây để
            // đảm bảo không sót rác trong index dù xóa từ phía nào.
            $document->profile?->unsearchable();
        });
    }
}

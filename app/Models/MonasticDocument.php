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
}

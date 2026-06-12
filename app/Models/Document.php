<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    protected $fillable = [
        'temple_id', 'monastic_id', 'uploaded_by', 'title', 'description',
        'file_path', 'file_name', 'file_type', 'file_size',
        'status', 'error_message', 'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function monastic(): BelongsTo
    {
        return $this->belongsTo(Monastic::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function getDownloadUrlAttribute(): string
    {
        return Storage::url($this->file_path);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}

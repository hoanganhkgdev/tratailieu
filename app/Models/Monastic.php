<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Monastic extends Model
{
    protected $fillable = [
        'temple_id', 'document_id', 'stt', 'full_name', 'religious_name',
        'rank', 'position', 'birth_year', 'phone',
    ];

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}

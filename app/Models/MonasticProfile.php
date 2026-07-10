<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonasticProfile extends Model
{
    protected $fillable = [
        'monastic_document_id', 'temple_id', 'province_id',
        'full_name', 'religious_name', 'birth_date', 'gender', 'ethnicity', 'nationality',
        'id_number', 'id_issued_date', 'id_issued_place', 'hometown', 'permanent_address',
        'current_address', 'monastic_cert_number', 'monastic_cert_date',
        'religion', 'religious_org', 'sect', 'classification', 'current_position',
        'ordination_date', 'concurrent_position', 'activity_scope', 'notes',
        'education_level', 'professional_qualification', 'religious_education_level',
        'training_institutions', 'languages',
        'activity_history', 'commendation_discipline', 'violations', 'congress_term',
        'phone', 'email', 'status',
    ];

    protected $casts = [
        'birth_date'          => 'date',
        'id_issued_date'      => 'date',
        'monastic_cert_date'  => 'date',
        'ordination_date'     => 'date',
        'classification'      => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(MonasticDocument::class, 'monastic_document_id');
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}

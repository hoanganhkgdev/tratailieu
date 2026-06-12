<?php

namespace App\Models;

use App\Jobs\ProcessMonasticEmbeddingJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonasticActivity extends Model
{
    protected $fillable = [
        'monastic_id', 'from_date', 'to_date', 'place', 'position',
        'commendation', 'violation', 'term_period',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date'   => 'date',
    ];

    protected static function booted(): void
    {
        // toSearchableText() của Monastic gồm cả lịch sử hoạt động — cần tạo lại
        // embedding mỗi khi danh sách hoạt động thay đổi để AI tra cứu được đầy đủ.
        $reembed = function (MonasticActivity $activity) {
            if ($activity->monastic) {
                ProcessMonasticEmbeddingJob::dispatch($activity->monastic);
            }
        };

        static::saved($reembed);
        static::deleted($reembed);
    }

    public function monastic(): BelongsTo
    {
        return $this->belongsTo(Monastic::class);
    }
}

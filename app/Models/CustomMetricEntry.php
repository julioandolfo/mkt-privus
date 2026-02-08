<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomMetricEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'custom_metric_id',
        'user_id',
        'value',
        'date',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'date' => 'date',
        'metadata' => 'array',
    ];

    // ===== RELATIONSHIPS =====

    public function metric(): BelongsTo
    {
        return $this->belongsTo(CustomMetric::class, 'custom_metric_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

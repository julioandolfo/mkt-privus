<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsDataPoint extends Model
{
    use BelongsToBrand;

    /** Registros com brand_id NULL são globais e permanecem visíveis para todas as marcas */
    public bool $brandScopeIncludesNull = true;

    protected $fillable = [
        'analytics_connection_id', 'brand_id', 'platform', 'metric_key',
        'value', 'date', 'dimension_key', 'dimension_value', 'extra',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'date' => 'date',
        'extra' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AnalyticsConnection::class, 'analytics_connection_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}

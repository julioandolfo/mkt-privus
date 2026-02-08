<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricGoal extends Model
{
    protected $fillable = [
        'custom_metric_id',
        'name',
        'target_value',
        'period',
        'start_date',
        'end_date',
        'baseline_value',
        'comparison_type',
        'notes',
        'is_active',
        'achieved',
        'achieved_at',
    ];

    protected $casts = [
        'target_value' => 'decimal:2',
        'baseline_value' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'achieved_at' => 'date',
        'is_active' => 'boolean',
        'achieved' => 'boolean',
    ];

    public function metric(): BelongsTo
    {
        return $this->belongsTo(CustomMetric::class, 'custom_metric_id');
    }

    /**
     * Calcula o progresso baseado nos entries da métrica no período da meta.
     */
    public function calculateProgress(): ?float
    {
        $metric = $this->metric;
        if (!$metric) return null;

        $latestInPeriod = $metric->entries()
            ->whereBetween('date', [$this->start_date, $this->end_date])
            ->latest('date')
            ->first();

        if (!$latestInPeriod) return 0;

        $currentValue = (float) $latestInPeriod->value;

        return match ($this->comparison_type) {
            'percentage' => $this->baseline_value > 0
                ? min(100, (($currentValue - $this->baseline_value) / $this->baseline_value) * 100 / ((float) $this->target_value / 100))
                : 0,
            'cumulative' => $this->target_value > 0
                ? min(100, ($metric->entries()->whereBetween('date', [$this->start_date, $this->end_date])->sum('value') / (float) $this->target_value) * 100)
                : 0,
            default => $this->target_value > 0
                ? min(100, ($currentValue / (float) $this->target_value) * 100)
                : 0,
        };
    }

    /**
     * Dias restantes até o fim do período.
     */
    public function daysRemaining(): int
    {
        return max(0, now()->diffInDays($this->end_date, false));
    }

    /**
     * Percentual do tempo decorrido.
     */
    public function timeElapsedPercent(): float
    {
        $total = $this->start_date->diffInDays($this->end_date);
        if ($total === 0) return 100;
        $elapsed = $this->start_date->diffInDays(now());
        return min(100, max(0, ($elapsed / $total) * 100));
    }

    public function isExpired(): bool
    {
        return $this->end_date->isPast();
    }
}

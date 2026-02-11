<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualAdEntry extends Model
{
    protected $fillable = [
        'brand_id', 'user_id', 'platform', 'platform_label',
        'date_start', 'date_end', 'amount', 'description',
    ];

    protected $casts = [
        'date_start' => 'date',
        'date_end' => 'date',
        'amount' => 'decimal:2',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Numero de dias no periodo
     */
    public function periodDays(): int
    {
        return $this->date_start->diffInDays($this->date_end) + 1;
    }

    /**
     * Valor diario distribuido
     */
    public function dailyAmount(): float
    {
        $days = $this->periodDays();
        return $days > 0 ? (float) $this->amount / $days : 0;
    }

    /**
     * Label da plataforma para exibicao
     */
    public function platformDisplayName(): string
    {
        if ($this->platform === 'other' && $this->platform_label) {
            return $this->platform_label;
        }

        return self::platformOptions()[$this->platform] ?? $this->platform;
    }

    /**
     * Scope: entradas que cobrem uma data especifica
     */
    public function scopeForDate($query, string $date)
    {
        return $query->where('date_start', '<=', $date)
            ->where('date_end', '>=', $date);
    }

    /**
     * Scope: entradas de uma marca em um periodo
     */
    public function scopeForBrandPeriod($query, int $brandId, string $startDate, string $endDate)
    {
        return $query->where('brand_id', $brandId)
            ->where('date_start', '<=', $endDate)
            ->where('date_end', '>=', $startDate);
    }

    /**
     * Opcoes de plataformas
     */
    public static function platformOptions(): array
    {
        return [
            'google_ads' => 'Google Ads',
            'meta_ads' => 'Meta Ads (Facebook/Instagram)',
            'tiktok_ads' => 'TikTok Ads',
            'linkedin_ads' => 'LinkedIn Ads',
            'pinterest_ads' => 'Pinterest Ads',
            'twitter_ads' => 'Twitter/X Ads',
            'other' => 'Outro',
        ];
    }
}

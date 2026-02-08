<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'user_id',
        'name',
        'description',
        'category',
        'metric_category_id',
        'unit',
        'value_type',
        'value_prefix',
        'value_suffix',
        'decimal_places',
        'direction',
        'currency_code',
        'color',
        'icon',
        'tags',
        'platform',
        'data_source',
        'formula',
        'tracking_frequency',
        'aggregation',
        'goal_value',
        'goal_period',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'goal_value' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'decimal_places' => 'integer',
        'tags' => 'array',
    ];

    // ===== RELATIONSHIPS =====

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function metricCategory(): BelongsTo
    {
        return $this->belongsTo(MetricCategory::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CustomMetricEntry::class)->orderBy('date', 'desc');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(MetricGoal::class);
    }

    public function activeGoals(): HasMany
    {
        return $this->hasMany(MetricGoal::class)->where('is_active', true);
    }

    // ===== SCOPES =====

    public function scopeForBrand($query, int $brandId)
    {
        return $query->where('brand_id', $brandId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByCategoryId($query, int $categoryId)
    {
        return $query->where('metric_category_id', $categoryId);
    }

    public function scopeByPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    public function scopeByTag($query, string $tag)
    {
        return $query->whereJsonContains('tags', $tag);
    }

    // ===== METHODS =====

    /**
     * Retorna o valor formatado de acordo com a configuração
     */
    public function formatValue(float $value): string
    {
        $decimals = $this->decimal_places ?? 2;
        $formatted = number_format($value, $decimals, ',', '.');

        $prefix = $this->value_prefix ?? '';
        $suffix = $this->value_suffix ?? '';

        // Fallback para unit legado
        if (!$prefix && !$suffix) {
            return match ($this->unit) {
                'currency' => 'R$ ' . number_format($value, 2, ',', '.'),
                'percentage' => number_format($value, 1, ',', '.') . '%',
                'followers' => number_format($value, 0, ',', '.'),
                'time_hours' => number_format($value, 1, ',', '.') . 'h',
                'time_minutes' => number_format($value, 0, ',', '.') . 'min',
                'weight_kg' => number_format($value, 2, ',', '.') . 'kg',
                'rate' => number_format($value, 2, ',', '.') . 'x',
                default => number_format($value, $decimals, ',', '.'),
            };
        }

        return trim("{$prefix} {$formatted} {$suffix}");
    }

    /**
     * Retorna o ultimo valor registrado
     */
    public function getLatestValue(): ?float
    {
        return $this->entries()->latest('date')->first()?->value;
    }

    /**
     * Calcula o progresso em relação à meta legada
     */
    public function getGoalProgress(): ?float
    {
        if (!$this->goal_value) {
            return null;
        }

        $latestValue = $this->getLatestValue();

        if ($latestValue === null) {
            return 0;
        }

        return min(100, ($latestValue / $this->goal_value) * 100);
    }

    /**
     * Calcula variação entre dois períodos
     */
    public function getVariation(string $period = '1month'): ?float
    {
        $days = match ($period) {
            '1week' => 7,
            '2weeks' => 14,
            '1month' => 30,
            '3months' => 90,
            '6months' => 180,
            '1year' => 365,
            default => 30,
        };

        $current = $this->entries()
            ->where('date', '>=', now()->subDays($days))
            ->latest('date')
            ->first();

        $previous = $this->entries()
            ->where('date', '<', now()->subDays($days))
            ->latest('date')
            ->first();

        if (!$current || !$previous || $previous->value == 0) {
            return null;
        }

        return round((($current->value - $previous->value) / abs($previous->value)) * 100, 1);
    }

    /**
     * Verifica se variação é positiva considerando a direção desejada
     */
    public function isVariationPositive(?float $variation): bool
    {
        if ($variation === null) return true;

        return match ($this->direction) {
            'up' => $variation >= 0,
            'down' => $variation <= 0,
            default => true,
        };
    }

    /**
     * Retorna todas as tags únicas da marca
     */
    public static function getAllTagsForBrand(int $brandId): array
    {
        $metrics = static::where('brand_id', $brandId)
            ->whereNotNull('tags')
            ->pluck('tags');

        $tags = [];
        foreach ($metrics as $metricTags) {
            if (is_array($metricTags)) {
                $tags = array_merge($tags, $metricTags);
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * Plataformas disponíveis para vincular
     */
    public static function availablePlatforms(): array
    {
        return [
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'linkedin' => 'LinkedIn',
            'tiktok' => 'TikTok',
            'youtube' => 'YouTube',
            'pinterest' => 'Pinterest',
            'google_ads' => 'Google Ads',
            'meta_ads' => 'Meta Ads',
            'google_analytics' => 'Google Analytics',
            'website' => 'Website',
            'email_marketing' => 'E-mail Marketing',
            'other' => 'Outro',
        ];
    }
}

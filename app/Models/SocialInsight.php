<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialInsight extends Model
{
    protected $fillable = [
        'social_account_id',
        'brand_id',
        'date',
        'followers_count',
        'following_count',
        'posts_count',
        'impressions',
        'reach',
        'engagement',
        'engagement_rate',
        'likes',
        'comments',
        'shares',
        'saves',
        'clicks',
        'video_views',
        'story_views',
        'reel_views',
        'followers_gained',
        'followers_lost',
        'net_followers',
        'audience_gender',
        'audience_age',
        'audience_cities',
        'audience_countries',
        'platform_data',
        'sync_status',
        'sync_error',
    ];

    protected $casts = [
        'date' => 'date',
        'engagement_rate' => 'decimal:4',
        'audience_gender' => 'array',
        'audience_age' => 'array',
        'audience_cities' => 'array',
        'audience_countries' => 'array',
        'platform_data' => 'array',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Aliases: metric_key do template => coluna/platform_data real
     * Necessario quando o template usa um nome diferente do armazenado
     */
    private static array $metricAliases = [
        'website_clicks' => ['column' => 'clicks', 'platform_data' => 'website_clicks'],
    ];

    /**
     * Busca o valor de uma metrica especifica pelo metric_key
     */
    public function getMetricValue(string $metricKey): mixed
    {
        // 1. Verificar nas colunas diretas
        if (array_key_exists($metricKey, $this->attributes) && $this->attributes[$metricKey] !== null) {
            return $this->attributes[$metricKey];
        }

        // 2. Verificar no platform_data
        if ($this->platform_data && isset($this->platform_data[$metricKey])) {
            return $this->platform_data[$metricKey];
        }

        // 3. Verificar aliases (ex: website_clicks -> column clicks)
        if (isset(self::$metricAliases[$metricKey])) {
            $alias = self::$metricAliases[$metricKey];

            // Tentar coluna alternativa
            if (isset($alias['column']) && array_key_exists($alias['column'], $this->attributes) && $this->attributes[$alias['column']] !== null) {
                return $this->attributes[$alias['column']];
            }

            // Tentar platform_data alternativo
            if (isset($alias['platform_data']) && $this->platform_data && isset($this->platform_data[$alias['platform_data']])) {
                return $this->platform_data[$alias['platform_data']];
            }
        }

        return null;
    }
}

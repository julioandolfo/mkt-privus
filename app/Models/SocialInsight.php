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
     * Busca o valor de uma metrica especifica pelo metric_key
     */
    public function getMetricValue(string $metricKey): mixed
    {
        // Primeiro verifica nas colunas diretas
        if (isset($this->attributes[$metricKey])) {
            return $this->attributes[$metricKey];
        }

        // Depois verifica no platform_data
        if ($this->platform_data && isset($this->platform_data[$metricKey])) {
            return $this->platform_data[$metricKey];
        }

        return null;
    }
}

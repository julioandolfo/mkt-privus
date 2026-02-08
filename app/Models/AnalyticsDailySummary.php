<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsDailySummary extends Model
{
    protected $fillable = [
        'brand_id', 'date',
        'sessions', 'users', 'new_users', 'pageviews', 'bounce_rate', 'avg_session_duration',
        'ad_spend', 'ad_impressions', 'ad_clicks', 'ad_conversions', 'ad_revenue', 'ad_ctr', 'ad_cpc', 'ad_roas',
        'search_impressions', 'search_clicks', 'search_ctr', 'search_position',
        'social_followers', 'social_engagement', 'social_reach', 'social_posts',
    ];

    protected $casts = [
        'date' => 'date',
        'bounce_rate' => 'decimal:2',
        'avg_session_duration' => 'decimal:2',
        'ad_spend' => 'decimal:2',
        'ad_revenue' => 'decimal:2',
        'ad_ctr' => 'decimal:4',
        'ad_cpc' => 'decimal:4',
        'ad_roas' => 'decimal:4',
        'search_ctr' => 'decimal:4',
        'search_position' => 'decimal:2',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}

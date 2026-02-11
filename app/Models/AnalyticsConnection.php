<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalyticsConnection extends Model
{
    protected $fillable = [
        'brand_id', 'user_id', 'platform', 'name', 'external_id', 'external_name',
        'access_token', 'refresh_token', 'token_expires_at', 'config', 'metadata',
        'is_active', 'last_synced_at', 'sync_status', 'sync_error',
    ];

    protected $casts = [
        'config' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dataPoints(): HasMany
    {
        return $this->hasMany(AnalyticsDataPoint::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    public static function platformLabels(): array
    {
        return [
            'google_analytics' => 'Google Analytics 4',
            'google_ads' => 'Google Ads',
            'google_search_console' => 'Google Search Console',
            'meta_ads' => 'Meta Ads',
            'woocommerce' => 'WooCommerce',
        ];
    }

    public static function platformColors(): array
    {
        return [
            'google_analytics' => '#F57C00',
            'google_ads' => '#4285F4',
            'google_search_console' => '#34A853',
            'meta_ads' => '#1877F2',
            'woocommerce' => '#96588A',
        ];
    }
}

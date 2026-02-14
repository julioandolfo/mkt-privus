<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlogCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'name', 'slug', 'description',
        'wp_category_id', 'wordpress_connection_id',
    ];

    protected $casts = [
        'wp_category_id' => 'integer',
        'wordpress_connection_id' => 'integer',
    ];

    // ===== RELATIONSHIPS =====

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(BlogArticle::class);
    }

    public function wordpressConnection(): BelongsTo
    {
        return $this->belongsTo(AnalyticsConnection::class, 'wordpress_connection_id');
    }

    // ===== SCOPES =====

    public function scopeForBrand($query, ?int $brandId)
    {
        return $query->when($brandId, fn($q) => $q->where('brand_id', $brandId))
            ->orWhereNull('brand_id');
    }

    public function scopeForConnection($query, ?int $connectionId)
    {
        return $query->when($connectionId, fn($q) => $q->where('wordpress_connection_id', $connectionId));
    }
}

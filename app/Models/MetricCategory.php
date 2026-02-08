<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MetricCategory extends Model
{
    protected $fillable = [
        'brand_id',
        'name',
        'slug',
        'color',
        'icon',
        'description',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::creating(function (MetricCategory $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(CustomMetric::class, 'metric_category_id');
    }

    public function activeMetricsCount(): int
    {
        return $this->metrics()->where('is_active', true)->count();
    }
}

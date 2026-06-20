<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogAutopilot extends Model
{
    use BelongsToBrand;

    use HasFactory;

    protected $fillable = [
        'brand_id',
        'name',
        'keywords',
        'enabled',
        'posts_per_week',
        'connection_id',
        'category_id',
        'require_approval',
        'auto_approve',
        'tone',
        'instructions',
        'cover_width',
        'cover_height',
        'last_run_at',
    ];

    protected $casts = [
        'keywords'         => 'array',
        'enabled'          => 'boolean',
        'require_approval' => 'boolean',
        'auto_approve'     => 'boolean',
        'posts_per_week'   => 'integer',
        'cover_width'      => 'integer',
        'cover_height'     => 'integer',
        'last_run_at'      => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AnalyticsConnection::class, 'connection_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    public function scopeForBrand($query, int $brandId)
    {
        return $query->where('brand_id', $brandId);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}

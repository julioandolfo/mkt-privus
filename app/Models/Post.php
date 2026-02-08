<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'brand_id',
        'user_id',
        'title',
        'caption',
        'hashtags',
        'type',
        'status',
        'platforms',
        'scheduled_at',
        'published_at',
        'ai_model_used',
        'ai_prompt',
        'metadata',
    ];

    protected $casts = [
        'type' => PostType::class,
        'status' => PostStatus::class,
        'platforms' => 'array',
        'hashtags' => 'array',
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
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

    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('order');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(PostSchedule::class);
    }

    // ===== SCOPES =====

    public function scopeForBrand($query, int $brandId)
    {
        return $query->where('brand_id', $brandId);
    }

    public function scopeDrafts($query)
    {
        return $query->where('status', PostStatus::Draft);
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', PostStatus::Scheduled);
    }

    public function scopePublished($query)
    {
        return $query->where('status', PostStatus::Published);
    }

    public function scopeForPlatform($query, SocialPlatform $platform)
    {
        return $query->whereJsonContains('platforms', $platform->value);
    }
}

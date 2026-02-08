<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'social_account_id',
        'platform',
        'status',
        'attempts',
        'max_attempts',
        'last_attempted_at',
        'scheduled_at',
        'published_at',
        'platform_post_id',
        'platform_post_url',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'platform' => SocialPlatform::class,
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'metadata' => 'array',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
    ];

    // ===== RELATIONSHIPS =====

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    // ===== SCOPES =====

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePublishing($query)
    {
        return $query->where('status', 'publishing');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeDueForPublishing($query)
    {
        return $query->where('status', 'pending')
            ->where('scheduled_at', '<=', now());
    }

    public function scopeRetryable($query)
    {
        return $query->where('status', 'failed')
            ->whereColumn('attempts', '<', 'max_attempts')
            ->where('last_attempted_at', '>=', now()->subDay());
    }

    // ===== METHODS =====

    public function canRetry(): bool
    {
        return $this->status === 'failed'
            && $this->attempts < $this->max_attempts;
    }

    public function markAsPublishing(): void
    {
        $this->update([
            'status' => 'publishing',
            'attempts' => $this->attempts + 1,
            'last_attempted_at' => now(),
        ]);
    }

    public function markAsPublished(string $platformPostId, ?string $platformPostUrl = null): void
    {
        $this->update([
            'status' => 'published',
            'published_at' => now(),
            'platform_post_id' => $platformPostId,
            'platform_post_url' => $platformPostUrl,
            'error_message' => null,
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }
}

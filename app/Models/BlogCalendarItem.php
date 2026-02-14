<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogCalendarItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'user_id',
        'scheduled_date', 'title', 'description', 'keywords', 'tone',
        'instructions', 'estimated_word_count',
        'wordpress_connection_id', 'blog_category_id',
        'status', 'article_id',
        'ai_model_used', 'batch_id', 'batch_status',
        'metadata',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'metadata' => 'array',
        'estimated_word_count' => 'integer',
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

    public function article(): BelongsTo
    {
        return $this->belongsTo(BlogArticle::class, 'article_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    public function wordpressConnection(): BelongsTo
    {
        return $this->belongsTo(AnalyticsConnection::class, 'wordpress_connection_id');
    }

    // ===== SCOPES =====

    public function scopeForBrand($query, ?int $brandId)
    {
        return $query->when($brandId, fn($q) => $q->where('brand_id', $brandId));
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForDateRange($query, string $start, string $end)
    {
        return $query->whereBetween('scheduled_date', [$start, $end]);
    }

    public function scopeDraftBatches($query)
    {
        return $query->where('batch_status', 'draft');
    }

    public function scopeApprovedOrNoBatch($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('batch_status')
                ->orWhere('batch_status', 'approved');
        });
    }

    // ===== ACCESSORS =====

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pauta Pendente',
            'generating' => 'Gerando Artigo...',
            'generated' => 'Artigo Gerado',
            'approved' => 'Aprovado',
            'published' => 'Publicado',
            'skipped' => 'Pulado',
            default => ucfirst($this->status),
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'yellow',
            'generating' => 'indigo',
            'generated' => 'blue',
            'approved' => 'emerald',
            'published' => 'green',
            'skipped' => 'gray',
            default => 'gray',
        };
    }

    // ===== METHODS =====

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isGenerated(): bool
    {
        return $this->status === 'generated';
    }

    public function hasArticle(): bool
    {
        return $this->article_id !== null;
    }

    public function canGenerateArticle(): bool
    {
        return in_array($this->status, ['pending', 'skipped'])
            && ($this->batch_status === null || $this->batch_status === 'approved');
    }
}

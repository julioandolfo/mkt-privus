<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BlogArticle extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'brand_id', 'user_id', 'wordpress_connection_id', 'blog_category_id',
        'title', 'slug', 'excerpt', 'content', 'cover_image_path',
        'meta_title', 'meta_description', 'meta_keywords',
        'tags', 'status',
        'wp_post_id', 'wp_post_url', 'published_at', 'scheduled_publish_at',
        'ai_model_used', 'tokens_used', 'ai_metadata',
    ];

    protected $casts = [
        'tags' => 'array',
        'ai_metadata' => 'array',
        'tokens_used' => 'integer',
        'wp_post_id' => 'integer',
        'published_at' => 'datetime',
        'scheduled_publish_at' => 'datetime',
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

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopePendingReview($query)
    {
        return $query->where('status', 'pending_review');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled')
            ->whereNotNull('scheduled_publish_at');
    }

    public function scopeReadyToPublish($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_publish_at', '<=', now());
    }

    public function scopeForConnection($query, ?int $connectionId)
    {
        return $query->when($connectionId, fn($q) => $q->where('wordpress_connection_id', $connectionId));
    }

    // ===== ACCESSORS =====

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'draft' => 'Rascunho',
            'pending_review' => 'Aguardando Revisão',
            'approved' => 'Aprovado',
            'publishing' => 'Publicando...',
            'published' => 'Publicado',
            'failed' => 'Falha na Publicação',
            'scheduled' => 'Agendado',
            default => ucfirst($this->status),
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'draft' => 'gray',
            'pending_review' => 'yellow',
            'approved' => 'blue',
            'publishing' => 'indigo',
            'published' => 'green',
            'failed' => 'red',
            'scheduled' => 'purple',
            default => 'gray',
        };
    }

    public function getWordCountAttribute(): int
    {
        if (!$this->content) return 0;
        return str_word_count(strip_tags($this->content));
    }

    public function getReadingTimeAttribute(): int
    {
        return max(1, (int) ceil($this->word_count / 200));
    }

    // ===== METHODS =====

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isPendingReview(): bool { return $this->status === 'pending_review'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isPublished(): bool { return $this->status === 'published'; }
    public function isScheduled(): bool { return $this->status === 'scheduled'; }
    public function isFailed(): bool { return $this->status === 'failed'; }

    public function canEdit(): bool
    {
        return in_array($this->status, ['draft', 'pending_review', 'approved', 'failed', 'scheduled']);
    }

    public function canPublish(): bool
    {
        return in_array($this->status, ['approved', 'failed'])
            && $this->wordpress_connection_id
            && $this->content
            && $this->title;
    }

    public function canApprove(): bool
    {
        return $this->status === 'pending_review';
    }

    public function canSchedule(): bool
    {
        return in_array($this->status, ['approved', 'failed'])
            && $this->wordpress_connection_id;
    }

    /**
     * Calcula uma pontuação SEO simples (0-100)
     */
    public function seoScore(): int
    {
        $score = 0;

        if ($this->meta_title) $score += 15;
        if ($this->meta_description) $score += 15;
        if ($this->meta_keywords) $score += 10;
        if ($this->excerpt) $score += 10;
        if ($this->cover_image_path) $score += 10;

        // Título entre 30-60 caracteres
        $titleLen = mb_strlen($this->title ?? '');
        if ($titleLen >= 30 && $titleLen <= 60) $score += 10;
        elseif ($titleLen > 0) $score += 5;

        // Meta description entre 120-160 caracteres
        $descLen = mb_strlen($this->meta_description ?? '');
        if ($descLen >= 120 && $descLen <= 160) $score += 10;
        elseif ($descLen > 0) $score += 5;

        // Conteúdo com pelo menos 300 palavras
        if ($this->word_count >= 800) $score += 10;
        elseif ($this->word_count >= 300) $score += 5;

        // Tags definidas
        if (!empty($this->tags)) $score += 5;

        // Categoria definida
        if ($this->blog_category_id) $score += 5;

        return min(100, $score);
    }

    /**
     * Gera slug único baseado no título
     */
    public static function generateUniqueSlug(string $title, ?int $excludeId = null): string
    {
        $slug = Str::slug($title);
        $original = $slug;
        $counter = 1;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists()
        ) {
            $slug = $original . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}

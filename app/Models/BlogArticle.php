<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BlogArticle extends Model
{
    use BelongsToBrand;

    use HasFactory, SoftDeletes;

    protected $fillable = [
        'brand_id', 'user_id', 'wordpress_connection_id', 'blog_category_id',
        'title', 'slug', 'excerpt', 'content', 'cover_image_path', 'cover_alt_text',
        'meta_title', 'meta_description', 'meta_keywords', 'focus_keyword',
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

    // ===== MUTATORS =====

    /**
     * Sanitiza o HTML do artigo ao gravar (allowlist), removendo <script>,
     * handlers de evento e URLs perigosas — o conteúdo é renderizado com v-html
     * no painel, então não pode conter XSS armazenado.
     */
    public function setContentAttribute(?string $value): void
    {
        $this->attributes['content'] = $value === null
            ? null
            : \App\Support\HtmlSanitizer::clean($value);
    }

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

    /**
     * Verifica se o artigo está completo — tem todos os elementos necessários para publicação.
     * Usado para auto-aprovação: se completo, pode ser aprovado/publicado sem revisão humana.
     */
    public function isComplete(): bool
    {
        return !empty($this->title)
            && !empty($this->content)
            && !empty($this->excerpt)
            && !empty($this->cover_image_path)
            && !empty($this->meta_title)
            && !empty($this->meta_description)
            && mb_strlen(strip_tags($this->content)) >= 200;
    }

    public function canSchedule(): bool
    {
        return in_array($this->status, ['approved', 'failed'])
            && $this->wordpress_connection_id;
    }

    /**
     * Calcula uma pontuação SEO (0-100). Cobre presença e tamanho dos campos,
     * uso do focus keyword no conteúdo (H2 + abertura) e quantidade de links
     * embutidos. Score só chega a 100 quando o básico E o uso real do focus
     * estão presentes — evita inflar quando faltam as coisas que importam.
     */
    public function seoScore(): int
    {
        $score = 0;

        // Presença dos campos (40 pts)
        if ($this->meta_title)          $score += 10;
        if ($this->meta_description)    $score += 10;
        if ($this->focus_keyword || $this->meta_keywords) $score += 5;
        if ($this->excerpt)             $score += 5;
        if ($this->cover_image_path)    $score += 5;
        if ($this->cover_alt_text)      $score += 5;

        // Tamanhos ideais (15 pts)
        $titleLen = mb_strlen($this->title ?? '');
        if ($titleLen >= 30 && $titleLen <= 60) $score += 7;
        elseif ($titleLen > 0)                  $score += 3;

        $descLen = mb_strlen($this->meta_description ?? '');
        if ($descLen >= 120 && $descLen <= 160) $score += 8;
        elseif ($descLen > 0)                   $score += 4;

        // Conteúdo & organização (15 pts)
        if ($this->word_count >= 900)      $score += 10;
        elseif ($this->word_count >= 500)  $score += 6;
        elseif ($this->word_count >= 300)  $score += 3;
        if (!empty($this->tags))           $score += 3;
        if ($this->blog_category_id)       $score += 2;

        // Uso real do focus keyword (20 pts) — o que de fato faz ranquear
        $focus = $this->effectiveFocusKeyword();
        if ($focus && $this->content) {
            $kwLower = mb_strtolower($focus);

            if (preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $this->content, $matches)) {
                foreach ($matches[1] as $h2Content) {
                    if (str_contains(mb_strtolower(strip_tags($h2Content)), $kwLower)) {
                        $score += 10;
                        break;
                    }
                }
            }

            $first100 = mb_substr(trim(strip_tags($this->content)), 0, 100);
            if (str_contains(mb_strtolower($first100), $kwLower)) {
                $score += 10;
            }
        }

        // Links no conteúdo (10 pts) — internos para a marca / âncoras descritivas
        if ($this->content) {
            preg_match_all('/<a\s+[^>]*href=["\'][^"\']+["\'][^>]*>/i', $this->content, $linkMatches);
            $linkCount = count($linkMatches[0] ?? []);
            if ($linkCount >= 3)      $score += 10;
            elseif ($linkCount >= 1)  $score += 5;
        }

        return min(100, $score);
    }

    /**
     * Focus keyword efetivo: prioriza o campo dedicado; cai para o 1º item
     * de meta_keywords (compatibilidade com artigos antigos).
     */
    public function effectiveFocusKeyword(): ?string
    {
        if (!empty($this->focus_keyword)) {
            return $this->focus_keyword;
        }
        if (is_string($this->meta_keywords) && $this->meta_keywords !== '') {
            $first = trim(explode(',', $this->meta_keywords)[0] ?? '');
            return $first !== '' ? $first : null;
        }
        return null;
    }

    /**
     * Gera slug único baseado no título
     */
    public static function generateUniqueSlug(string $title, ?int $excludeId = null): string
    {
        $slug = Str::slug($title);
        $original = $slug;
        $counter = 1;

        // Sem o escopo de marca: o índice unique de slug é GLOBAL, então a
        // checagem precisa enxergar todas as marcas — senão duas marcas geram o
        // mesmo slug e o INSERT estoura QueryException (23000).
        while (static::withoutGlobalScope('brand')->withTrashed()
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

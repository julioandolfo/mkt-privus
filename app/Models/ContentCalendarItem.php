<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentCalendarItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'user_id',
        'scheduled_date',
        'title',
        'description',
        'category',
        'platforms',
        'post_type',
        'tone',
        'instructions',
        'status',
        'post_id',
        'suggestion_id',
        'ai_model_used',
        'batch_id',
        'batch_status',
        'metadata',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'platforms' => 'array',
        'metadata' => 'array',
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

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(ContentSuggestion::class, 'suggestion_id');
    }

    // ===== SCOPES =====

    public function scopeForBrand($query, int $brandId)
    {
        return $query->where('brand_id', $brandId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeGenerated($query)
    {
        return $query->where('status', 'generated');
    }

    public function scopeReadyToGenerate($query)
    {
        return $query->where('status', 'pending')
            ->where(fn($q) => $q->whereNull('batch_status')->orWhere('batch_status', 'approved'))
            ->where('scheduled_date', '>=', now()->toDateString());
    }

    public function scopeForDateRange($query, string $start, string $end)
    {
        return $query->whereBetween('scheduled_date', [$start, $end]);
    }

    public function scopeDraft($query)
    {
        return $query->where('batch_status', 'draft');
    }

    public function scopeApprovedBatch($query)
    {
        return $query->where('batch_status', 'approved');
    }

    public function scopeNotDraft($query)
    {
        return $query->where(fn($q) => $q->whereNull('batch_status')->orWhere('batch_status', '!=', 'draft'));
    }

    public function scopeForBatch($query, string $batchId)
    {
        return $query->where('batch_id', $batchId);
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

    public function isDraft(): bool
    {
        return $this->batch_status === 'draft';
    }

    public function isBatchApproved(): bool
    {
        return $this->batch_status === 'approved';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pauta pendente',
            'generated' => 'Post gerado',
            'approved' => 'Aprovado',
            'published' => 'Publicado',
            'skipped' => 'Pulado',
            default => ucfirst($this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'pending' => 'yellow',
            'generated' => 'blue',
            'approved' => 'indigo',
            'published' => 'green',
            'skipped' => 'gray',
            default => 'gray',
        };
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            'dica' => 'Dica',
            'novidade' => 'Novidade',
            'bastidores' => 'Bastidores',
            'promocao' => 'Promocao',
            'educativo' => 'Educativo',
            'inspiracional' => 'Inspiracional',
            'engajamento' => 'Engajamento',
            'produto' => 'Produto',
            'institucional' => 'Institucional',
            'depoimento' => 'Depoimento',
            'lancamento' => 'Lancamento',
            'tendencia' => 'Tendencia',
            default => ucfirst($this->category),
        };
    }
}

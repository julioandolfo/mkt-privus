<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentSuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'content_rule_id',
        'title',
        'caption',
        'hashtags',
        'platforms',
        'post_type',
        'status',
        'ai_model_used',
        'tokens_used',
        'rejection_reason',
        'metadata',
    ];

    protected $casts = [
        'hashtags' => 'array',
        'platforms' => 'array',
        'metadata' => 'array',
        'tokens_used' => 'integer',
    ];

    // ===== RELATIONSHIPS =====

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function contentRule(): BelongsTo
    {
        return $this->belongsTo(ContentRule::class);
    }

    // ===== SCOPES =====

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeConverted($query)
    {
        return $query->where('status', 'converted');
    }

    // ===== METHODS =====

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFromRule(): bool
    {
        return $this->content_rule_id !== null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pendente',
            'approved' => 'Aprovado',
            'rejected' => 'Rejeitado',
            'converted' => 'Convertido em Post',
            default => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'pending' => 'yellow',
            'approved' => 'blue',
            'rejected' => 'red',
            'converted' => 'green',
            default => 'gray',
        };
    }
}

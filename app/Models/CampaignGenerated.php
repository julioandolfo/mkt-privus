<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignGenerated extends Model
{
    use BelongsToBrand;

    use HasFactory;

    protected $table = 'campaigns_generated';

    protected $fillable = [
        'brand_id',
        'user_id',
        'name',
        'concept',
        'objective',
        'platforms',
        'format',
        'posts_data',
        'metadata',
        'status',
        'rejected_at',
        'rejection_reason',
        'converted_at',
        'converted_posts',
        'deleted_at',
    ];

    protected $casts = [
        'platforms' => 'array',
        'posts_data' => 'array',
        'metadata' => 'array',
        'converted_posts' => 'array',
        'rejected_at' => 'datetime',
        'converted_at' => 'datetime',
        'deleted_at' => 'datetime',
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

    // ===== SCOPES =====

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeConverted($query)
    {
        return $query->where('status', 'converted');
    }

    public function scopeNotDeleted($query)
    {
        return $query->whereNull('deleted_at');
    }

    // ===== METHODS =====

    /**
     * Rejeita a campanha
     */
    public function reject(string $reason = null): void
    {
        $this->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Marca como convertida (posts criados)
     */
    public function markAsConverted(array $postIds): void
    {
        $this->update([
            'status' => 'converted',
            'converted_at' => now(),
            'converted_posts' => $postIds,
        ]);
    }

    /**
     * Soft delete da campanha
     */
    public function softDelete(): void
    {
        $this->update([
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);
    }

    /**
     * Restaura uma campanha deletada
     */
    public function restore(): void
    {
        $this->update([
            'status' => 'active',
            'deleted_at' => null,
        ]);
    }

    /**
     * Retorna label do status
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'active' => 'Ativa',
            'rejected' => 'Rejeitada',
            'converted' => 'Convertida',
            'deleted' => 'Excluída',
            default => 'Desconhecido',
        };
    }

    /**
     * Retorna cor do status
     */
    public function statusColor(): string
    {
        return match ($this->status) {
            'active' => 'green',
            'rejected' => 'red',
            'converted' => 'blue',
            'deleted' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Verifica se pode ser deletada
     */
    public function canDelete(): bool
    {
        return in_array($this->status, ['active', 'rejected']);
    }

    /**
     * Verifica se pode ser rejeitada
     */
    public function canReject(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Verifica se pode ser convertida
     */
    public function canConvert(): bool
    {
        return in_array($this->status, ['active', 'rejected']);
    }
}

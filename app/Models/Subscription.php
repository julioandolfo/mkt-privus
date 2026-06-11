<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'mp_preapproval_id',
        'trial_ends_at',
        'current_period_end',
        'cancelled_at',
        'metadata',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Assinatura dá acesso ao sistema?
     */
    public function isValid(): bool
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => true,
            self::STATUS_TRIALING => $this->trial_ends_at?->isFuture() ?? false,
            default => false,
        };
    }

    public function onTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING
            && ($this->trial_ends_at?->isFuture() ?? false);
    }

    /**
     * Mapeia o status do preapproval do Mercado Pago para o status local
     */
    public static function statusFromMercadoPago(string $mpStatus): ?string
    {
        return match ($mpStatus) {
            'authorized' => self::STATUS_ACTIVE,
            'paused' => self::STATUS_PAUSED,
            'cancelled' => self::STATUS_CANCELLED,
            'pending' => self::STATUS_PENDING,
            default => null,
        };
    }
}

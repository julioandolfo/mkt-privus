<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'platform',
        'platform_user_id',
        'username',
        'display_name',
        'avatar_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'platform' => SocialPlatform::class,
        'token_expires_at' => 'datetime',
        'scopes' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    // ===== RELATIONSHIPS =====

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    // ===== METHODS =====

    public function isTokenExpired(): bool
    {
        if (!$this->token_expires_at) {
            return false;
        }

        return $this->token_expires_at->isPast();
    }

    public function needsRefresh(): bool
    {
        if (!$this->token_expires_at) {
            return false;
        }

        // Renovar quando faltar menos de 24h para expirar
        return $this->token_expires_at->subDay()->isPast();
    }

    /**
     * Verifica se o token tem erro registrado (não renovável).
     */
    public function hasTokenError(): bool
    {
        return !empty($this->metadata['token_error'] ?? null);
    }
}

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

        // Para tokens de curta duração (Google/YouTube = 1h), renovar com 10min de antecedência
        // Para outros, renovar quando faltar menos de 1h
        $buffer = $this->isShortLivedToken() ? 10 : 60; // minutos

        return $this->token_expires_at->subMinutes($buffer)->isPast();
    }

    /**
     * Verifica se é um token de curta duração (ex: Google = 1h)
     */
    public function isShortLivedToken(): bool
    {
        $platform = $this->platform->value ?? $this->platform;
        return in_array($platform, ['youtube', 'google']);
    }

    /**
     * Verifica se o token tem erro registrado (não renovável).
     */
    public function hasTokenError(): bool
    {
        return !empty($this->metadata['token_error'] ?? null);
    }

    /**
     * Garante que o token está válido, renovando automaticamente se necessário.
     * Retorna true se o token é válido, false se não pode ser renovado.
     */
    public function ensureFreshToken(): bool
    {
        // Se não tem data de expiração ou token ainda é válido, retornar OK
        if (!$this->token_expires_at || !$this->needsRefresh()) {
            return true;
        }

        // Sem refresh_token, não tem como renovar
        if (!$this->refresh_token) {
            return false;
        }

        try {
            /** @var \App\Services\Social\SocialOAuthService $oauthService */
            $oauthService = app(\App\Services\Social\SocialOAuthService::class);
            $result = $oauthService->refreshToken($this);

            if ($result && !empty($result['access_token'])) {
                $updateData = [
                    'access_token' => $result['access_token'],
                    'token_expires_at' => isset($result['expires_in'])
                        ? now()->addSeconds($result['expires_in'])
                        : now()->addHour(),
                ];

                if (!empty($result['refresh_token'])) {
                    $updateData['refresh_token'] = $result['refresh_token'];
                }

                // Limpar erros anteriores de token
                $metadata = $this->metadata ?? [];
                unset($metadata['token_error'], $metadata['token_error_at']);
                $updateData['metadata'] = $metadata;

                $this->update($updateData);
                $this->refresh();

                \App\Models\SystemLog::info('oauth', 'token.auto_refresh', "Token renovado automaticamente: @{$this->username} ({$this->platform->value})", [
                    'account_id' => $this->id,
                    'new_expires_at' => $updateData['token_expires_at']->toDateTimeString(),
                ]);

                return true;
            }

            return false;
        } catch (\Throwable $e) {
            \App\Models\SystemLog::error('oauth', 'token.auto_refresh.error', "Erro ao renovar token: @{$this->username}: {$e->getMessage()}", [
                'account_id' => $this->id,
            ]);
            return false;
        }
    }

    /**
     * Retorna o access_token garantindo que está fresco.
     * Retorna null se o token expirou e não pode ser renovado.
     */
    public function getFreshToken(): ?string
    {
        if ($this->ensureFreshToken()) {
            return $this->access_token;
        }

        return null;
    }
}

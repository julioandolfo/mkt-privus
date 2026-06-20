<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    use BelongsToBrand;

    use HasFactory;

    /**
     * Escopo global de marca (BelongsToBrand): contas órfãs (brand_id NULL,
     * ex: marca excluída) só ficam visíveis para o dono (user_id) — ou para
     * todos quando legadas (user_id NULL).
     */
    public function applyBrandScopeConstraint(Builder $query, ?int $brandId): void
    {
        if ($brandId !== null) {
            $query->where('social_accounts.brand_id', $brandId);
        } else {
            $query->whereRaw('1 = 0');
        }

        $query->orWhere(function (Builder $orphans) {
            $orphans->whereNull('social_accounts.brand_id')
                ->where(function (Builder $owner) {
                    $owner->whereNull('social_accounts.user_id')
                        ->orWhere('social_accounts.user_id', \Illuminate\Support\Facades\Auth::id());
                });
        });
    }

    /**
     * Global scope: esconde rows cujo platform não está mais presente no enum
     * SocialPlatform (ex: 'pinterest' após remoção). Evita hidratação falhar
     * com ValueError. Rows ficam no banco para histórico mas somem das queries.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('valid_platform', function (Builder $query) {
            $validPlatforms = array_map(fn($c) => $c->value, SocialPlatform::cases());
            $query->whereIn('platform', $validPlatforms);
        });
    }

    protected $fillable = [
        'brand_id',
        'user_id',
        'platform',
        'source',
        'postiz_integration_id',
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
        // Tokens OAuth criptografados em repouso
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
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

    // ===== SCOPES =====

    public function scopeDirect($query)
    {
        return $query->where('source', 'direct');
    }

    public function scopePostiz($query)
    {
        return $query->where('source', 'postiz');
    }

    // ===== METHODS =====

    public function isPostizBacked(): bool
    {
        return $this->source === 'postiz';
    }

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
     * Indica se esta conta consegue renovar o token automaticamente.
     *
     * Meta (Facebook/Instagram) NÃO usa refresh_token: o token de longa
     * duração (60 dias) é estendido via fb_exchange_token usando o próprio
     * access_token atual — desde que ele ainda esteja válido. Por isso essas
     * contas são renováveis mesmo sem refresh_token.
     *
     * Google/YouTube usam refresh_token (que não expira) para gerar novos
     * access_tokens de 1h.
     */
    public function canAutoRefresh(): bool
    {
        if (empty($this->access_token)) {
            return false;
        }

        $platform = $this->platform->value ?? $this->platform;

        if (in_array($platform, ['facebook', 'instagram'])) {
            // Meta só consegue estender enquanto o token atual ainda é válido.
            return !$this->isTokenExpired();
        }

        if (in_array($platform, ['youtube', 'google'])) {
            return !empty($this->refresh_token);
        }

        return !empty($this->refresh_token);
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

        // Plataforma não pode renovar (ex: Google sem refresh_token,
        // ou Meta com token já expirado/sem access_token)
        if (!$this->canAutoRefresh()) {
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

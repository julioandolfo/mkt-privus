<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\SystemLog;
use App\Services\Social\SocialOAuthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job que roda a cada hora via Scheduler.
 * Busca contas sociais cujo token está prestes a expirar
 * e renova usando o refresh_token de cada plataforma.
 *
 * Fluxo por plataforma:
 * - Meta (Facebook/Instagram): fb_exchange_token para user tokens, page tokens não expiram
 * - Google/YouTube: refresh_token -> novo access_token (refresh_token não expira)
 * - LinkedIn: refresh_token -> novo access_token + refresh_token
 * - TikTok: refresh_token -> novo access_token + refresh_token
 * - Pinterest: refresh_token -> novo access_token + refresh_token
 */
class RefreshSocialTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('autopilot');
    }

    public function handle(SocialOAuthService $oauthService): void
    {
        // Buscar contas ativas que precisam de refresh
        // Token expirado OU expira em menos de 15 minutos (cobre Google/YouTube 1h tokens)
        $accounts = SocialAccount::where('is_active', true)
            ->whereNotNull('token_expires_at')
            ->whereNotNull('refresh_token')
            ->where('token_expires_at', '<=', now()->addMinutes(15))
            ->get();

        if ($accounts->isEmpty()) {
            return;
        }

        $refreshed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($accounts as $account) {
            $platform = $account->platform->value ?? $account->platform;

            try {
                $result = $oauthService->refreshToken($account);

                if ($result && !empty($result['access_token'])) {
                    $updateData = [
                        'access_token' => $result['access_token'],
                        'token_expires_at' => isset($result['expires_in'])
                            ? now()->addSeconds($result['expires_in'])
                            : now()->addDays(60),
                    ];

                    // Atualizar refresh_token se um novo foi fornecido
                    if (!empty($result['refresh_token'])) {
                        $updateData['refresh_token'] = $result['refresh_token'];
                    }

                    $account->update($updateData);
                    $refreshed++;

                    SystemLog::info('oauth', 'token.refresh.success', "Token renovado: @{$account->username} ({$platform})", [
                        'account_id' => $account->id,
                        'new_expires_at' => $updateData['token_expires_at']->toDateTimeString(),
                        'has_new_refresh' => !empty($result['refresh_token']),
                    ]);
                } else {
                    $failed++;

                    SystemLog::warning('oauth', 'token.refresh.failed', "Nao foi possivel renovar token: @{$account->username} ({$platform})", [
                        'account_id' => $account->id,
                        'has_refresh_token' => !empty($account->refresh_token),
                        'expires_at' => $account->token_expires_at?->toDateTimeString(),
                    ]);

                    // Se o token já expirou e não pode ser renovado, marcar para o usuário
                    if ($account->isTokenExpired()) {
                        $account->update([
                            'metadata' => array_merge($account->metadata ?? [], [
                                'token_error' => 'Token expirado e não renovável. Reconecte a conta.',
                                'token_error_at' => now()->toIso8601String(),
                            ]),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $failed++;

                SystemLog::error('oauth', 'token.refresh.error', "Erro ao renovar token: @{$account->username} ({$platform}): {$e->getMessage()}", [
                    'account_id' => $account->id,
                    'exception' => get_class($e),
                ]);
            }
        }

        if ($refreshed > 0 || $failed > 0) {
            SystemLog::info('oauth', 'token.refresh.complete', "Refresh de tokens: {$refreshed} renovados, {$failed} falharam de {$accounts->count()} contas");
        }
    }
}

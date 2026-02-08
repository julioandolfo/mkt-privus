<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job que roda a cada hora via Scheduler.
 * Busca contas sociais cujo token esta prestes a expirar
 * e tenta renovar. Por enquanto apenas registra log - a renovacao
 * real sera implementada quando as APIs forem integradas.
 */
class RefreshSocialTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('autopilot');
    }

    public function handle(): void
    {
        $accounts = SocialAccount::where('is_active', true)
            ->whereNotNull('token_expires_at')
            ->get()
            ->filter(fn($account) => $account->needsRefresh());

        if ($accounts->isEmpty()) {
            return;
        }

        Log::info("Autopilot TokenRefresh: {$accounts->count()} contas precisam de refresh");

        foreach ($accounts as $account) {
            try {
                $this->refreshToken($account);
            } catch (\Exception $e) {
                Log::error("Autopilot TokenRefresh: Falha ao renovar token", [
                    'account_id' => $account->id,
                    'platform' => $account->platform->value,
                    'username' => $account->username,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Renova o token de uma conta social.
     * TODO: Implementar refresh real por plataforma quando APIs forem integradas.
     */
    private function refreshToken(SocialAccount $account): void
    {
        Log::info("Autopilot TokenRefresh: Simulando renovação de token", [
            'account_id' => $account->id,
            'platform' => $account->platform->label(),
            'username' => $account->username,
            'expires_at' => $account->token_expires_at->toDateTimeString(),
        ]);

        // Simulacao: estender validade do token por mais 60 dias
        $account->update([
            'token_expires_at' => now()->addDays(60),
        ]);

        Log::info("Autopilot TokenRefresh: Token renovado (simulado) para @{$account->username}");
    }
}

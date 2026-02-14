<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\SystemLog;
use App\Services\Social\SocialInsightsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job que sincroniza insights de uma única conta social.
 * Disparado automaticamente após conexão de uma nova conta
 * para que os dados (engajamento, alcance, etc.) apareçam imediatamente.
 */
class SyncSingleSocialAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;
    public int $backoff = 30;

    public function __construct(
        public int $accountId
    ) {
        $this->onQueue('default');
    }

    public function handle(SocialInsightsService $service): void
    {
        $account = SocialAccount::find($this->accountId);

        if (!$account || !$account->is_active) {
            return;
        }

        $platform = $account->platform->value ?? $account->platform;

        SystemLog::info('social', 'auto_sync.start', "Auto-sync apos conexao: @{$account->username} ({$platform})", [
            'account_id' => $account->id,
        ]);

        try {
            $result = $service->syncAccount($account);

            if ($result) {
                $engRate = $result->engagement_rate ? " ({$result->engagement_rate}%)" : '';
                SystemLog::info('social', 'auto_sync.success', "Auto-sync concluido: @{$account->username} - Seguidores: {$result->followers_count}, Engajamento: {$result->engagement}{$engRate}, Alcance: {$result->reach}, Impressoes: {$result->impressions}", [
                    'account_id' => $account->id,
                    'followers' => $result->followers_count,
                    'engagement' => $result->engagement,
                    'engagement_rate' => $result->engagement_rate,
                    'reach' => $result->reach,
                    'impressions' => $result->impressions,
                    'likes' => $result->likes,
                    'comments' => $result->comments,
                    'saves' => $result->saves,
                    'shares' => $result->shares,
                    'clicks' => $result->clicks,
                ]);
            } else {
                SystemLog::warning('social', 'auto_sync.no_result', "Auto-sync sem resultado: @{$account->username} ({$platform})", [
                    'account_id' => $account->id,
                ]);
            }
        } catch (\Throwable $e) {
            SystemLog::error('social', 'auto_sync.error', "Erro no auto-sync: @{$account->username}: {$e->getMessage()}", [
                'account_id' => $account->id,
                'exception' => get_class($e),
            ]);

            throw $e; // Re-throw para que o queue possa re-tentar
        }
    }
}

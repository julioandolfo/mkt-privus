<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Models\SocialMetricTemplate;
use App\Services\Social\SocialInsightsService;
use Illuminate\Console\Command;

class SyncSocialInsights extends Command
{
    protected $signature = 'social:sync-insights
                            {--account= : ID de uma conta especifica}
                            {--brand= : ID de uma brand especifica}
                            {--all : Sincronizar todas as contas}
                            {--seed-templates : Criar/atualizar templates de metricas}';

    protected $description = 'Sincroniza insights e metricas das contas sociais conectadas';

    public function handle(SocialInsightsService $service): int
    {
        // Seed templates se solicitado
        if ($this->option('seed-templates')) {
            $this->info('Criando/atualizando templates de metricas sociais...');
            SocialMetricTemplate::seedDefaults();
            $this->info('Templates criados com sucesso!');

            if (!$this->option('account') && !$this->option('brand') && !$this->option('all')) {
                return self::SUCCESS;
            }
        }

        $accountId = $this->option('account');
        $brandId = $this->option('brand');

        if ($accountId) {
            $account = SocialAccount::find($accountId);
            if (!$account) {
                $this->error("Conta #{$accountId} nao encontrada.");
                return self::FAILURE;
            }

            $this->info("Sincronizando insights de: {$account->display_name} ({$account->platform->value})...");
            $result = $service->syncAccount($account);
            $this->displayResult($account, $result);

        } elseif ($brandId) {
            $this->info("Sincronizando todas as contas da brand #{$brandId}...");
            $results = $service->syncBrand($brandId);
            $this->displayResults($results);

        } elseif ($this->option('all')) {
            $this->info('Sincronizando TODAS as contas ativas...');
            $results = $service->syncAll();
            $this->displayResults($results);

        } else {
            $this->warn('Especifique --account=ID, --brand=ID ou --all');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function displayResult(SocialAccount $account, $result): void
    {
        if ($result) {
            $this->info("  [OK] {$account->display_name}: Seguidores={$result->followers_count}, Engajamento={$result->engagement}");
        } else {
            $this->error("  [ERRO] {$account->display_name}: Falha ao sincronizar");
        }
    }

    private function displayResults(array $results): void
    {
        $success = 0;
        $errors = 0;

        foreach ($results as $accountId => $result) {
            $account = SocialAccount::find($accountId);
            if (!$account) continue;

            if ($result) {
                $success++;
                $this->info("  [OK] {$account->display_name} ({$account->platform->value}): Seguidores={$result->followers_count}");
            } else {
                $errors++;
                $this->error("  [ERRO] {$account->display_name} ({$account->platform->value}): Falha");
            }
        }

        $this->newLine();
        $this->info("Concluido: {$success} ok, {$errors} erros de " . count($results) . " contas.");
    }
}

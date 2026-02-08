<?php

namespace App\Console\Commands;

use App\Jobs\ProcessScheduledPostsJob;
use App\Jobs\RetryFailedPostsJob;
use App\Jobs\RefreshSocialTokensJob;
use App\Models\PostSchedule;
use Illuminate\Console\Command;

/**
 * Comando para processar manualmente o autopilot.
 * Util para testes e debug sem depender do scheduler.
 */
class AutopilotProcessCommand extends Command
{
    protected $signature = 'autopilot:process
        {--retry : Incluir re-tentativa de posts com falha}
        {--refresh-tokens : Incluir renovação de tokens}
        {--sync : Executar de forma síncrona (sem fila)}
        {--status : Apenas exibir status atual sem processar}';

    protected $description = 'Processa posts agendados do Autopilot manualmente';

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->showStatus();
        }

        $this->info('Autopilot: Iniciando processamento manual...');
        $this->newLine();

        // Processar posts agendados
        $due = PostSchedule::dueForPublishing()->count();
        $this->info("Posts prontos para publicação: {$due}");

        if ($due > 0) {
            if ($this->option('sync')) {
                $this->info('Executando de forma síncrona...');
                (new ProcessScheduledPostsJob)->handle();
                $this->info('Processamento síncrono concluído.');
            } else {
                ProcessScheduledPostsJob::dispatch();
                $this->info('Job ProcessScheduledPostsJob despachado na fila.');
            }
        }

        // Re-tentar falhas
        if ($this->option('retry')) {
            $retryable = PostSchedule::retryable()->count();
            $this->newLine();
            $this->info("Posts com falha passíveis de re-tentativa: {$retryable}");

            if ($retryable > 0) {
                if ($this->option('sync')) {
                    (new RetryFailedPostsJob)->handle();
                    $this->info('Re-tentativas executadas.');
                } else {
                    RetryFailedPostsJob::dispatch();
                    $this->info('Job RetryFailedPostsJob despachado na fila.');
                }
            }
        }

        // Renovar tokens
        if ($this->option('refresh-tokens')) {
            $this->newLine();
            if ($this->option('sync')) {
                (new RefreshSocialTokensJob)->handle();
                $this->info('Renovação de tokens executada.');
            } else {
                RefreshSocialTokensJob::dispatch();
                $this->info('Job RefreshSocialTokensJob despachado na fila.');
            }
        }

        $this->newLine();
        $this->info('Autopilot: Processamento concluído!');

        return Command::SUCCESS;
    }

    private function showStatus(): int
    {
        $this->info('=== Autopilot Status ===');
        $this->newLine();

        $pending = PostSchedule::pending()->count();
        $publishing = PostSchedule::publishing()->count();
        $published = PostSchedule::published()->count();
        $failed = PostSchedule::failed()->count();
        $due = PostSchedule::dueForPublishing()->count();
        $retryable = PostSchedule::retryable()->count();

        $this->table(
            ['Métrica', 'Quantidade'],
            [
                ['Pendentes', $pending],
                ['Prontos para publicar (due)', $due],
                ['Publicando agora', $publishing],
                ['Publicados (total)', $published],
                ['Com falha (total)', $failed],
                ['Passíveis de re-tentativa', $retryable],
            ]
        );

        // Proximos 5 schedules
        $upcoming = PostSchedule::pending()
            ->with('post:id,title,caption')
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get();

        if ($upcoming->isNotEmpty()) {
            $this->newLine();
            $this->info('Próximos agendamentos:');
            $this->table(
                ['ID', 'Post', 'Plataforma', 'Agendado para'],
                $upcoming->map(fn($s) => [
                    $s->id,
                    mb_substr($s->post?->title ?? $s->post?->caption ?? '-', 0, 30),
                    $s->platform->label(),
                    $s->scheduled_at->format('d/m/Y H:i'),
                ])->toArray()
            );
        }

        return Command::SUCCESS;
    }
}

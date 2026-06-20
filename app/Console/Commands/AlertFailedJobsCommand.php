<?php

namespace App\Console\Commands;

use App\Models\SystemLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Alerta o administrador quando novos jobs falham na fila.
 * Agendado de hora em hora (routes/console.php). Envia e-mail para
 * ADMIN_ALERT_EMAIL quando configurado; sempre registra em SystemLog.
 */
class AlertFailedJobsCommand extends Command
{
    protected $signature = 'system:alert-failed-jobs';

    protected $description = 'Notifica o admin sobre novos jobs falhados na última hora';

    public function handle(): int
    {
        $lastSeenId = (int) Cache::get('alert_failed_jobs.last_seen_id', 0);

        $newFailures = DB::table('failed_jobs')
            ->where('id', '>', $lastSeenId)
            ->orderBy('id')
            ->get(['id', 'queue', 'exception', 'failed_at']);

        if ($newFailures->isEmpty()) {
            $this->info('Nenhum job falhado novo.');

            return self::SUCCESS;
        }

        Cache::forever('alert_failed_jobs.last_seen_id', (int) $newFailures->last()->id);

        $summary = $newFailures
            ->groupBy('queue')
            ->map(fn ($jobs, $queue) => "{$queue}: {$jobs->count()}")
            ->implode(', ');

        SystemLog::error('queue', 'failed_jobs.alert', "{$newFailures->count()} job(s) falharam ({$summary})", [
            'count' => $newFailures->count(),
            'first_error' => mb_substr((string) $newFailures->first()->exception, 0, 500),
        ]);

        if ($adminEmail = \App\Support\BillingSettings::adminAlertEmail()) {
            $lines = $newFailures->take(10)->map(function ($job) {
                $error = strtok((string) $job->exception, "\n");

                return "- [{$job->failed_at}] fila {$job->queue}: {$error}";
            })->implode("\n");

            Mail::raw(
                "Foram detectados {$newFailures->count()} job(s) falhados na fila ({$summary}).\n\n"
                . "Primeiras falhas:\n{$lines}\n\n"
                . "Use `php artisan queue:retry all` após corrigir a causa.",
                fn ($message) => $message
                    ->to($adminEmail)
                    ->subject('[' . config('app.name') . "] {$newFailures->count()} job(s) falharam na fila")
            );
        }

        $this->warn("{$newFailures->count()} job(s) falhados notificados.");

        return self::SUCCESS;
    }
}

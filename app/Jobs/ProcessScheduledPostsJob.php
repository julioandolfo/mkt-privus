<?php

namespace App\Jobs;

use App\Models\PostSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job que roda a cada minuto via Scheduler.
 * Busca todos os PostSchedules prontos para publicacao e despacha
 * um PublishPostJob para cada um.
 */
class ProcessScheduledPostsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('autopilot');
    }

    public function handle(): void
    {
        $schedules = PostSchedule::dueForPublishing()
            ->with(['post', 'socialAccount'])
            ->limit(50) // Processar no maximo 50 por vez
            ->get();

        if ($schedules->isEmpty()) {
            return;
        }

        Log::info("Autopilot: Encontrados {$schedules->count()} schedules para publicação");

        foreach ($schedules as $schedule) {
            // Marcar como "publishing" para evitar reprocessamento
            $schedule->markAsPublishing();

            // Atualizar status do post para Publishing se estiver Scheduled
            $post = $schedule->post;
            if ($post && $post->status->value === 'scheduled') {
                $post->update(['status' => 'publishing']);
            }

            // Despachar job de publicacao individual
            PublishPostJob::dispatch($schedule)
                ->onQueue('autopilot');
        }

        Log::info("Autopilot: {$schedules->count()} jobs de publicação despachados");
    }
}

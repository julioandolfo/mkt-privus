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
 * Job que roda a cada 15 minutos via Scheduler.
 * Busca schedules que falharam mas ainda podem ser tentados novamente
 * e re-despacha PublishPostJob para cada um.
 */
class RetryFailedPostsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('autopilot');
    }

    public function handle(): void
    {
        $retryable = PostSchedule::retryable()
            ->with(['post', 'socialAccount'])
            ->limit(20)
            ->get();

        if ($retryable->isEmpty()) {
            return;
        }

        Log::info("Autopilot Retry: Encontrados {$retryable->count()} schedules para re-tentativa");

        foreach ($retryable as $schedule) {
            $schedule->markAsPublishing();

            PublishPostJob::dispatch($schedule)
                ->onQueue('autopilot');
        }

        Log::info("Autopilot Retry: {$retryable->count()} jobs re-despachados");
    }
}

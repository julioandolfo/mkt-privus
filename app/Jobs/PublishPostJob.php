<?php

namespace App\Jobs;

use App\Enums\PostStatus;
use App\Models\PostSchedule;
use App\Services\Social\PostPublisherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job que publica um post individual em uma plataforma especifica.
 * Executa o publisher da plataforma e atualiza status do schedule e do post.
 */
class PublishPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Numero maximo de tentativas do job.
     */
    public $tries = 3;

    /**
     * Backoff exponencial em segundos: 1min, 5min, 15min.
     */
    public $backoff = [60, 300, 900];

    /**
     * Timeout do job em segundos.
     */
    public $timeout = 120;

    public function __construct(
        public readonly PostSchedule $schedule,
    ) {
        $this->onQueue('autopilot');
    }

    public function handle(PostPublisherService $publisherService): void
    {
        $schedule = $this->schedule->fresh(['post.media', 'socialAccount']);

        if (!$schedule || !$schedule->post) {
            Log::warning('Autopilot: Schedule ou Post não encontrado', [
                'schedule_id' => $this->schedule->id,
            ]);
            return;
        }

        // Se ja foi publicado (processamento duplicado), ignorar
        if ($schedule->status === 'published') {
            return;
        }

        Log::info("Autopilot: Publicando schedule #{$schedule->id}", [
            'post_id' => $schedule->post_id,
            'platform' => $schedule->platform->value,
        ]);

        $result = $publisherService->publish($schedule);

        if ($result->success) {
            $schedule->markAsPublished(
                $result->platformPostId,
                $result->platformPostUrl,
            );

            // Verificar se todos os schedules do post foram concluidos
            $this->checkPostCompletion($schedule);
        } else {
            $schedule->markAsFailed($result->errorMessage ?? 'Erro desconhecido');

            // Se esgotou tentativas, verificar conclusao do post mesmo assim
            if (!$schedule->canRetry()) {
                $this->checkPostCompletion($schedule);
            }
        }
    }

    /**
     * Verifica se todos os schedules de um Post terminaram (publicados ou falharam sem retry).
     * Se sim, atualiza o status do Post.
     */
    private function checkPostCompletion(PostSchedule $schedule): void
    {
        $post = $schedule->post;

        if (!$post) {
            return;
        }

        $allSchedules = $post->schedules()->get();
        $pendingOrPublishing = $allSchedules->filter(fn($s) => in_array($s->status, ['pending', 'publishing']));

        // Ainda tem schedules em andamento
        if ($pendingOrPublishing->isNotEmpty()) {
            return;
        }

        $published = $allSchedules->where('status', 'published');
        $failed = $allSchedules->filter(fn($s) => $s->status === 'failed' && !$s->canRetry());

        if ($published->count() === $allSchedules->count()) {
            // Todos publicados com sucesso
            $post->update([
                'status' => PostStatus::Published,
                'published_at' => now(),
            ]);
            Log::info("Autopilot: Post #{$post->id} totalmente publicado");
        } elseif ($published->isNotEmpty() && $failed->isNotEmpty()) {
            // Parcialmente publicado - considerar publicado com ressalvas
            $post->update([
                'status' => PostStatus::Published,
                'published_at' => now(),
            ]);
            Log::warning("Autopilot: Post #{$post->id} parcialmente publicado ({$published->count()}/{$allSchedules->count()})");
        } elseif ($failed->count() === $allSchedules->count()) {
            // Todos falharam
            $post->update(['status' => PostStatus::Failed]);
            Log::error("Autopilot: Post #{$post->id} falhou em todas as plataformas");
        }
    }

    /**
     * Handler para quando o job falha definitivamente.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Autopilot: Job de publicação falhou definitivamente", [
            'schedule_id' => $this->schedule->id,
            'error' => $exception->getMessage(),
        ]);

        $this->schedule->markAsFailed("Falha definitiva: {$exception->getMessage()}");
    }
}

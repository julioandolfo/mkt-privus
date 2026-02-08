<?php

namespace App\Http\Controllers;

use App\Jobs\PublishPostJob;
use App\Models\PostSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AutopilotController extends Controller
{
    /**
     * Dashboard do Autopilot - monitoramento de publicacoes automaticas
     */
    public function index(Request $request): Response
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return Inertia::render('Social/Autopilot/Index', [
                'stats' => $this->emptyStats(),
                'upcoming' => [],
                'recent' => [],
                'failed' => [],
            ]);
        }

        // Buscar schedules da marca ativa (via posts da marca)
        $brandPostIds = $brand->posts()->pluck('id');

        // Estatisticas
        $stats = [
            'pending' => PostSchedule::whereIn('post_id', $brandPostIds)->pending()->count(),
            'publishing' => PostSchedule::whereIn('post_id', $brandPostIds)->publishing()->count(),
            'published_today' => PostSchedule::whereIn('post_id', $brandPostIds)
                ->published()
                ->whereDate('published_at', today())
                ->count(),
            'failed' => PostSchedule::whereIn('post_id', $brandPostIds)->failed()->count(),
            'retryable' => PostSchedule::whereIn('post_id', $brandPostIds)->retryable()->count(),
            'published_total' => PostSchedule::whereIn('post_id', $brandPostIds)->published()->count(),
        ];

        // Proximos agendamentos (pendentes)
        $upcoming = PostSchedule::whereIn('post_id', $brandPostIds)
            ->whereIn('status', ['pending', 'publishing'])
            ->with(['post:id,title,caption,platforms', 'socialAccount:id,platform,username'])
            ->orderBy('scheduled_at')
            ->limit(20)
            ->get()
            ->map(fn($s) => $this->formatSchedule($s));

        // Publicados recentemente
        $recent = PostSchedule::whereIn('post_id', $brandPostIds)
            ->published()
            ->with(['post:id,title,caption,platforms', 'socialAccount:id,platform,username'])
            ->orderByDesc('published_at')
            ->limit(15)
            ->get()
            ->map(fn($s) => $this->formatSchedule($s));

        // Com falha
        $failed = PostSchedule::whereIn('post_id', $brandPostIds)
            ->failed()
            ->with(['post:id,title,caption,platforms', 'socialAccount:id,platform,username'])
            ->orderByDesc('last_attempted_at')
            ->limit(15)
            ->get()
            ->map(fn($s) => $this->formatSchedule($s));

        return Inertia::render('Social/Autopilot/Index', [
            'stats' => $stats,
            'upcoming' => $upcoming,
            'recent' => $recent,
            'failed' => $failed,
        ]);
    }

    /**
     * Re-tentar publicacao manualmente
     */
    public function retry(Request $request, PostSchedule $schedule): RedirectResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand || !$brand->posts()->where('id', $schedule->post_id)->exists()) {
            abort(403, 'Acesso negado.');
        }

        if (!$schedule->canRetry() && $schedule->status !== 'failed') {
            return redirect()->back()->withErrors([
                'retry' => 'Este agendamento não pode ser re-tentado.',
            ]);
        }

        // Forcar retry mesmo que max_attempts tenha sido atingido (manual override)
        $schedule->update([
            'max_attempts' => $schedule->max_attempts + 1,
        ]);

        $schedule->markAsPublishing();

        PublishPostJob::dispatch($schedule)->onQueue('autopilot');

        return redirect()->back()->with('success', 'Re-tentativa agendada com sucesso!');
    }

    // ===== PRIVATE =====

    private function formatSchedule(PostSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'post_id' => $schedule->post_id,
            'post_title' => $schedule->post?->title ?: mb_substr($schedule->post?->caption ?? '', 0, 50) . '...',
            'platform' => $schedule->platform->value,
            'platform_label' => $schedule->platform->label(),
            'platform_color' => $schedule->platform->color(),
            'status' => $schedule->status,
            'attempts' => $schedule->attempts,
            'max_attempts' => $schedule->max_attempts,
            'scheduled_at' => $schedule->scheduled_at?->format('d/m/Y H:i'),
            'published_at' => $schedule->published_at?->format('d/m/Y H:i'),
            'last_attempted_at' => $schedule->last_attempted_at?->format('d/m/Y H:i'),
            'error_message' => $schedule->error_message,
            'platform_post_url' => $schedule->platform_post_url,
            'can_retry' => $schedule->status === 'failed',
            'account_username' => $schedule->socialAccount?->username,
        ];
    }

    private function emptyStats(): array
    {
        return [
            'pending' => 0,
            'publishing' => 0,
            'published_today' => 0,
            'failed' => 0,
            'retryable' => 0,
            'published_total' => 0,
        ];
    }
}

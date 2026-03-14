<?php

namespace App\Http\Controllers;

use App\Jobs\PublishPostJob;
use App\Models\Brand;
use App\Models\PostSchedule;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AutopilotController extends Controller
{
    /**
     * Dashboard do Autopilot - monitoramento + configurações
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
                'config' => $this->defaultConfig(),
                'socialAccounts' => [],
            ]);
        }

        $brandPostIds = $brand->posts()->pluck('id');

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

        $upcoming = PostSchedule::whereIn('post_id', $brandPostIds)
            ->whereIn('status', ['pending', 'publishing'])
            ->with(['post:id,title,caption,platforms', 'socialAccount:id,platform,username'])
            ->orderBy('scheduled_at')
            ->limit(20)
            ->get()
            ->map(fn($s) => $this->formatSchedule($s));

        $recent = PostSchedule::whereIn('post_id', $brandPostIds)
            ->published()
            ->with(['post:id,title,caption,platforms', 'socialAccount:id,platform,username'])
            ->orderByDesc('published_at')
            ->limit(15)
            ->get()
            ->map(fn($s) => $this->formatSchedule($s));

        $failed = PostSchedule::whereIn('post_id', $brandPostIds)
            ->failed()
            ->with(['post:id,title,caption,platforms', 'socialAccount:id,platform,username'])
            ->orderByDesc('last_attempted_at')
            ->limit(15)
            ->get()
            ->map(fn($s) => $this->formatSchedule($s));

        $cfg = $brand->getContentEngineConfig();

        $socialAccounts = $brand->socialAccounts()
            ->where('is_active', true)
            ->get(['id', 'platform', 'username'])
            ->map(fn($a) => [
                'id' => $a->id,
                'platform' => $a->platform,
                'username' => $a->username,
            ]);

        return Inertia::render('Social/Autopilot/Index', [
            'stats' => $stats,
            'upcoming' => $upcoming,
            'recent' => $recent,
            'failed' => $failed,
            'config' => [
                'enabled'             => (bool) ($cfg['social_autopilot_enabled'] ?? false),
                'posts_per_week'      => (int)  ($cfg['social_posts_per_week'] ?? 5),
                'platforms'           => $cfg['preferred_platforms'] ?? [],
                'post_types'          => $cfg['post_types'] ?? ['feed', 'carousel'],
                'generate_images'     => (bool) ($cfg['generate_images'] ?? true),
                'auto_hashtags'       => (bool) ($cfg['auto_hashtags'] ?? true),
                'hashtag_count'       => (int)  ($cfg['hashtag_count'] ?? 8),
                'caption_style'       => $cfg['caption_style'] ?? 'medium',
                'include_cta'         => (bool) ($cfg['include_cta'] ?? true),
                'include_emojis'      => (bool) ($cfg['include_emojis'] ?? true),
                'tone'                => $cfg['content_tone_override'] ?? '',
                'instructions'        => $cfg['social_instructions'] ?? '',
                'best_times_source'   => $cfg['best_times_source'] ?? 'auto',
                'auto_approve'        => (bool) ($cfg['social_auto_approve'] ?? false),
            ],
            'socialAccounts' => $socialAccounts,
        ]);
    }

    /**
     * Salvar configurações do autopilot social
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled'           => 'boolean',
            'posts_per_week'    => 'integer|min:1|max:21',
            'platforms'         => 'nullable|array',
            'post_types'        => 'nullable|array',
            'generate_images'   => 'boolean',
            'auto_hashtags'     => 'boolean',
            'hashtag_count'     => 'integer|min:0|max:30',
            'caption_style'     => 'string|in:short,medium,long',
            'include_cta'       => 'boolean',
            'include_emojis'    => 'boolean',
            'tone'              => 'nullable|string|max:100',
            'instructions'      => 'nullable|string|max:2000',
            'best_times_source' => 'string|in:auto,data,default',
            'auto_approve'      => 'boolean',
        ]);

        $brand = $request->user()->getActiveBrand();
        if (!$brand) {
            return response()->json(['success' => false, 'error' => 'Nenhuma marca selecionada.']);
        }

        $brand->updateContentEngineConfig([
            'social_autopilot_enabled' => $validated['enabled'] ?? false,
            'social_posts_per_week'    => $validated['posts_per_week'] ?? 5,
            'preferred_platforms'      => $validated['platforms'] ?? [],
            'post_types'               => $validated['post_types'] ?? ['feed', 'carousel'],
            'generate_images'          => $validated['generate_images'] ?? true,
            'auto_hashtags'            => $validated['auto_hashtags'] ?? true,
            'hashtag_count'            => $validated['hashtag_count'] ?? 8,
            'caption_style'            => $validated['caption_style'] ?? 'medium',
            'include_cta'              => $validated['include_cta'] ?? true,
            'include_emojis'           => $validated['include_emojis'] ?? true,
            'content_tone_override'    => $validated['tone'] ?? null,
            'social_instructions'      => $validated['instructions'] ?? '',
            'best_times_source'        => $validated['best_times_source'] ?? 'auto',
            'social_auto_approve'      => $validated['auto_approve'] ?? false,
        ]);

        SystemLog::info('social', 'autopilot.config_saved', 'Configurações de autopilot social salvas', [
            'brand_id' => $brand->id,
            'enabled'  => $validated['enabled'] ?? false,
        ]);

        return response()->json(['success' => true, 'message' => 'Configurações salvas com sucesso.']);
    }

    /**
     * Executar ciclo do autopilot agora (gerar sugestões + calendário)
     */
    public function runNow(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand) {
            return response()->json(['success' => false, 'error' => 'Nenhuma marca selecionada.']);
        }

        \App\Jobs\GenerateSmartSuggestionsJob::dispatch();
        \App\Jobs\GenerateCalendarPostsJob::dispatch();

        SystemLog::info('social', 'autopilot.manual_run', 'Autopilot social disparado manualmente', [
            'brand_id' => $brand->id,
            'user_id'  => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'message' => 'Autopilot iniciado! Sugestões e posts do calendário serão gerados em instantes.']);
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

    /**
     * Limpar todas as publicações com falha (deletar os schedules)
     */
    public function clearFailed(Request $request): RedirectResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand) {
            return redirect()->back()->withErrors(['brand' => 'Nenhuma marca selecionada.']);
        }

        $brandPostIds = $brand->posts()->pluck('id');

        $deleted = PostSchedule::whereIn('post_id', $brandPostIds)
            ->where('status', 'failed')
            ->delete();

        SystemLog::info('social', 'autopilot.clear_failed', "{$deleted} agendamento(s) com falha removido(s)", [
            'brand_id' => $brand->id,
            'deleted_count' => $deleted,
        ]);

        return redirect()->back()->with('success', "{$deleted} falha(s) removida(s) com sucesso.");
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

    private function defaultConfig(): array
    {
        return [
            'enabled'           => false,
            'posts_per_week'    => 5,
            'platforms'         => [],
            'post_types'        => ['feed', 'carousel'],
            'generate_images'   => true,
            'auto_hashtags'     => true,
            'hashtag_count'     => 8,
            'caption_style'     => 'medium',
            'include_cta'       => true,
            'include_emojis'    => true,
            'tone'              => '',
            'instructions'      => '',
            'best_times_source' => 'auto',
            'auto_approve'      => false,
        ];
    }
}

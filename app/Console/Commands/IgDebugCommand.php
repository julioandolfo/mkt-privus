<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostSchedule;
use App\Models\SystemLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Despeja mídia, schedules e logs de publicação (canal social) de um post,
 * destacando os campos que explicam falhas no Instagram (media_type, is_video,
 * status_code) e o casamento extensão x type da mídia.
 */
class IgDebugCommand extends Command
{
    protected $signature = 'ig:debug
        {post? : ID do post (omita para listar as falhas recentes)}
        {--full : Mostrar o context JSON completo de cada log}
        {--limit=80 : Máximo de logs a exibir}
        {--skip-http : Não testar a URL pública da mídia via HTTP}';

    protected $description = 'Diagnóstico de publicação social de um post (mídia, schedules e logs)';

    public function handle(): int
    {
        if ($this->argument('post') === null) {
            return $this->listRecentFailures();
        }

        $postId = (int) $this->argument('post');
        $post = Post::with(['media', 'schedules.socialAccount'])->find($postId);

        if (!$post) {
            $this->error("Post #{$postId} não encontrado.");
            return Command::FAILURE;
        }

        $this->info("=== Post #{$post->id} ===");
        $this->line('Status: ' . $post->status->value . '   Tipo: ' . ($post->type?->value ?? '-'));
        $this->line('Plataformas: ' . implode(', ', $post->platforms ?? []));
        $this->line('Legenda: ' . mb_substr((string) $post->caption, 0, 90));
        $this->newLine();

        $this->renderMedia($post);
        $this->renderSchedules($post);
        $this->renderLogs($post);

        return Command::SUCCESS;
    }

    /**
     * Lista os schedules que falharam recentemente, com o ID do post, para
     * o usuário descobrir qual post inspecionar com `ig:debug <id>`.
     */
    private function listRecentFailures(): int
    {
        $failures = PostSchedule::with('post:id,title')
            ->where('status', 'failed')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        if ($failures->isEmpty()) {
            $this->info('Nenhum schedule com falha recente.');
            return Command::SUCCESS;
        }

        $this->info('Falhas recentes (use o post_id em: php artisan ig:debug <id>)');
        $this->table(
            ['post_id', 'título', 'plataforma', 'tent.', 'quando', 'erro'],
            $failures->map(fn($s) => [
                $s->post_id,
                mb_substr((string) ($s->post?->title ?? '-'), 0, 28),
                $s->platform->value ?? $s->platform,
                $s->attempts . '/' . $s->max_attempts,
                $s->updated_at?->format('d/m H:i') ?? '-',
                mb_substr((string) $s->error_message, 0, 50),
            ])->toArray()
        );

        return Command::SUCCESS;
    }

    private function renderMedia(Post $post): void
    {
        $this->info('--- Mídia ---');

        if ($post->media->isEmpty()) {
            $this->warn('Nenhuma mídia.');
            $this->newLine();
            return;
        }

        $rows = [];
        $warnings = [];
        $checkHttp = !$this->option('skip-http');

        foreach ($post->media->sortBy('order') as $media) {
            $exists = $media->file_path ? Storage::disk('public')->exists($media->file_path) : false;
            $url = rtrim((string) config('app.url'), '/') . '/storage/' . ltrim((string) $media->file_path, '/');
            $ext = strtolower(pathinfo((string) $media->file_path, PATHINFO_EXTENSION));

            $http = $checkHttp ? $this->probeUrl($url) : ['label' => '(pulado)', 'ok' => true, 'note' => null];

            $rows[] = [
                $media->id,
                $media->type,
                $ext ?: '-',
                $exists ? 'sim' : 'NÃO',
                $http['label'],
                $url,
            ];

            $looksVideo = in_array($ext, ['mp4', 'mov', 'm4v', 'webm', 'avi'], true);
            if ($looksVideo && $media->type !== 'video') {
                $warnings[] = "Mídia #{$media->id}: extensão .{$ext} é vídeo mas type='{$media->type}'. "
                    . 'O Instagram recebe como imagem → trava processando e dá timeout. '
                    . "Corrija para type='video' (ou re-envie como .mp4).";
            }
            if (!$exists) {
                $warnings[] = "Mídia #{$media->id}: arquivo NÃO existe no disco public ({$media->file_path}). "
                    . 'A URL pública dará 404 e o Instagram não consegue baixar.';
            }
            if ($http['note']) {
                $warnings[] = "Mídia #{$media->id}: {$http['note']}";
            }
        }

        $this->table(['ID', 'type', 'ext', 'no disco?', 'HTTP', 'public_url'], $rows);

        foreach ($warnings as $w) {
            $this->warn('⚠ ' . $w);
        }

        $this->newLine();
    }

    private function renderSchedules(Post $post): void
    {
        $this->info('--- Schedules ---');

        if ($post->schedules->isEmpty()) {
            $this->warn('Nenhum schedule.');
            $this->newLine();
            return;
        }

        $this->table(
            ['ID', 'plataforma', 'conta', 'status', 'tent.', 'platform_post_id', 'erro'],
            $post->schedules->map(fn($s) => [
                $s->id,
                $s->platform->value ?? $s->platform,
                $s->socialAccount?->username ?? ('#' . $s->social_account_id),
                $s->status,
                $s->attempts . '/' . $s->max_attempts,
                $s->platform_post_id ?? '-',
                mb_substr((string) $s->error_message, 0, 70),
            ])->toArray()
        );

        $this->newLine();
    }

    private function renderLogs(Post $post): void
    {
        $this->info('--- Logs (canal social) ---');

        $limit = max(1, (int) $this->option('limit'));

        $logs = SystemLog::where('channel', 'social')
            ->where('context->post_id', $post->id)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($logs->isEmpty()) {
            // Fallback: alguns logs guardam post_id aninhado; busca textual no JSON.
            $logs = SystemLog::where('channel', 'social')
                ->where('context', 'like', '%"post_id":' . $post->id . '%')
                ->orderBy('id')
                ->limit($limit)
                ->get();
        }

        if ($logs->isEmpty()) {
            $this->warn('Nenhum log social encontrado para este post.');
            return;
        }

        foreach ($logs as $log) {
            $time = $log->created_at->format('d/m H:i:s');
            $this->line("[{$time}] " . strtoupper($log->level) . " {$log->action} — {$log->message}");

            $ctx = is_array($log->context) ? $log->context : [];

            if ($this->option('full')) {
                $this->line('  ' . json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                continue;
            }

            $highlights = $this->highlight($ctx);
            if ($highlights !== '') {
                $this->line('  ' . $highlights);
            }
        }

        $this->newLine();
        $this->line('Dica: --full mostra o context completo; --limit=200 traz mais logs.');
    }

    /**
     * Testa a URL pública como o Instagram faria: status HTTP + content-type.
     */
    private function probeUrl(string $url): array
    {
        try {
            $resp = Http::timeout(8)->withHeaders(['User-Agent' => 'PrivusBot/ig-debug'])->head($url);
            $status = $resp->status();
            $ctype = strtolower((string) $resp->header('Content-Type'));
            $short = trim(explode(';', $ctype)[0]) ?: '?';

            $note = null;
            if ($status >= 400) {
                $note = "URL pública retornou HTTP {$status} — o Instagram não consegue baixar a mídia "
                    . '(confira php artisan storage:link e se o arquivo existe no disco).';
            } elseif (!str_starts_with($ctype, 'image/') && !str_starts_with($ctype, 'video/')) {
                $note = "URL pública retornou '{$short}' em vez de imagem/vídeo "
                    . '(provável storage:link quebrado servindo HTML). O Instagram trava processando e dá timeout.';
            }

            return ['label' => "{$status} {$short}", 'ok' => $note === null, 'note' => $note];
        } catch (\Throwable $e) {
            return ['label' => 'ERRO', 'ok' => false, 'note' => 'Falha ao acessar a URL pública: ' . $e->getMessage()];
        }
    }

    /**
     * Extrai os campos mais úteis do context para o debug do Instagram.
     */
    private function highlight(array $ctx): string
    {
        $parts = [];

        foreach (['media_type', 'is_video', 'status_code', 'waited_s', 'status', 'creation_id', 'platform_post_id', 'url', 'error'] as $key) {
            if (array_key_exists($key, $ctx) && $ctx[$key] !== null && $ctx[$key] !== '') {
                $val = is_scalar($ctx[$key]) ? (string) $ctx[$key] : json_encode($ctx[$key], JSON_UNESCAPED_SLASHES);
                $parts[] = "{$key}={$val}";
            }
        }

        foreach (['body', 'api_response'] as $key) {
            if (!empty($ctx[$key])) {
                $parts[] = "{$key}=" . mb_substr(json_encode($ctx[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 220);
            }
        }

        return implode('  ', $parts);
    }
}

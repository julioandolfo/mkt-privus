<?php

namespace App\Console\Commands;

use App\Models\BlogArticle;
use App\Models\BlogCalendarItem;
use App\Models\SystemLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Despeja artigo, pauta vinculada, logs da geração de capa (cover.* / cover_job.*)
 * e jobs falhados do GenerateBlogCoverJob — para entender por que um artigo
 * ficou sem imagem de capa.
 */
class BlogDebugCoverCommand extends Command
{
    protected $signature = 'blog:debug-cover
        {article? : ID do artigo (omita para listar artigos recentes sem capa)}
        {--full : Mostrar o context JSON completo de cada log}
        {--limit=80 : Máximo de logs a exibir}
        {--skip-http : Não testar a URL pública da capa via HTTP}';

    protected $description = 'Diagnóstico da geração de capa de um artigo de blog';

    public function handle(): int
    {
        if ($this->argument('article') === null) {
            return $this->listRecentMissingCovers();
        }

        $articleId = (int) $this->argument('article');
        $article   = BlogArticle::with('brand')->find($articleId);

        if (!$article) {
            $this->error("Artigo #{$articleId} não encontrado.");
            return Command::FAILURE;
        }

        $this->renderHeader($article);
        $this->renderCover($article);
        $this->renderCalendarItem($article);
        $this->renderLogs($article);
        $this->renderFailedJobs($article);

        return Command::SUCCESS;
    }

    private function listRecentMissingCovers(): int
    {
        $articles = BlogArticle::with('brand:id,name')
            ->whereNull('cover_image_path')
            ->latest()
            ->limit(20)
            ->get();

        if ($articles->isEmpty()) {
            $this->info('Nenhum artigo recente sem capa.');
            return Command::SUCCESS;
        }

        $this->info('Artigos recentes sem capa (use o id em: php artisan blog:debug-cover <id>)');
        $this->table(
            ['id', 'título', 'marca', 'status', 'criado'],
            $articles->map(fn($a) => [
                $a->id,
                mb_substr((string) $a->title, 0, 40),
                $a->brand?->name ?? '-',
                $a->status,
                $a->created_at?->format('d/m H:i') ?? '-',
            ])->toArray()
        );

        return Command::SUCCESS;
    }

    private function renderHeader(BlogArticle $article): void
    {
        $this->info("=== Artigo #{$article->id} ===");
        $this->line('Título: ' . mb_substr((string) $article->title, 0, 90));
        $this->line('Marca: ' . ($article->brand?->name ?? '-') . '   Status: ' . $article->status);
        $this->line('Agendado para: ' . ($article->scheduled_publish_at?->format('d/m/Y H:i') ?? '-'));
        $this->newLine();
    }

    private function renderCover(BlogArticle $article): void
    {
        $this->info('--- Capa ---');

        if (empty($article->cover_image_path)) {
            $this->warn('Sem capa: cover_image_path está vazio. A geração não terminou (ou falhou). Veja os logs abaixo.');
            $this->newLine();
            return;
        }

        $exists = Storage::disk('public')->exists($article->cover_image_path);
        $url    = rtrim((string) config('app.url'), '/') . '/storage/' . ltrim($article->cover_image_path, '/');
        $http   = $this->option('skip-http') ? ['label' => '(pulado)', 'note' => null] : $this->probeUrl($url);

        $this->table(['caminho', 'no disco?', 'HTTP', 'public_url'], [[
            $article->cover_image_path,
            $exists ? 'sim' : 'NÃO',
            $http['label'],
            $url,
        ]]);

        if (!$exists) {
            $this->warn('⚠ O cover_image_path está preenchido mas o arquivo não existe no disco public. Algo apagou ou nunca foi escrito de fato.');
        }
        if ($http['note']) {
            $this->warn('⚠ ' . $http['note']);
        }

        $this->newLine();
    }

    private function renderCalendarItem(BlogArticle $article): void
    {
        $this->info('--- Pauta (BlogCalendarItem) ---');

        // O artigo guarda calendar_item_id em ai_metadata.calendar_item_id (criado pela calendar service).
        $calItemId = data_get($article->ai_metadata, 'calendar_item_id');

        $item = $calItemId
            ? BlogCalendarItem::find($calItemId)
            : BlogCalendarItem::where('article_id', $article->id)->first();

        if (!$item) {
            $this->line('Nenhuma pauta vinculada (artigo criado fora do calendário).');
            $this->newLine();
            return;
        }

        $meta = $item->metadata ?? [];
        $this->line("Pauta #{$item->id}   data: " . ($item->scheduled_date ?? '-'));
        $this->line('cover_width: '  . ($meta['cover_width']  ?? '(default 1750)'));
        $this->line('cover_height: ' . ($meta['cover_height'] ?? '(default 650)'));
        $this->newLine();
    }

    private function renderLogs(BlogArticle $article): void
    {
        $this->info('--- Logs (canal blog: cover.* / cover_job.*) ---');

        $limit = max(1, (int) $this->option('limit'));

        $logs = SystemLog::where('channel', 'blog')
            ->where(function ($q) {
                $q->where('action', 'like', 'cover.%')
                  ->orWhere('action', 'like', 'cover_job.%');
            })
            ->where(function ($q) use ($article) {
                // article_id (cover_job.*) ou brand_id (cover.* — não tem article_id)
                $q->where('context->article_id', $article->id)
                  ->orWhere(function ($q2) use ($article) {
                      $q2->where('context->brand_id', $article->brand_id)
                         ->whereBetween('created_at', [
                             $article->created_at?->copy()->subMinutes(2) ?? now()->subHour(),
                             $article->created_at?->copy()->addHour() ?? now(),
                         ]);
                  });
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values();

        if ($logs->isEmpty()) {
            $this->warn('Nenhum log de capa encontrado. Possíveis causas: o GenerateBlogCoverJob ainda não rodou, ou está na fila, ou foi descartado sem logar.');
            $this->newLine();
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
    }

    private function renderFailedJobs(BlogArticle $article): void
    {
        $this->info('--- failed_jobs (GenerateBlogCoverJob recentes) ---');

        // Filtra pelo nome do job; tenta refinar pelo articleId serializado.
        $rows = DB::table('failed_jobs')
            ->where('payload', 'like', '%GenerateBlogCoverJob%')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'queue', 'payload', 'exception', 'failed_at']);

        if ($rows->isEmpty()) {
            $this->line('Nenhum job falhado do GenerateBlogCoverJob.');
            $this->newLine();
            return;
        }

        $articleNeedle = ':' . $article->id . ';';
        $exact = $rows->filter(fn($r) => str_contains((string) $r->payload, "articleId\";i:{$article->id};")
            || str_contains((string) $r->payload, $articleNeedle));

        $show = $exact->isNotEmpty() ? $exact : $rows;

        foreach ($show as $row) {
            $time = $row->failed_at;
            $excPreview = mb_substr((string) $row->exception, 0, 220);
            $marker = $exact->contains(fn($r) => $r->id === $row->id) ? ' [✓ provável deste artigo]' : '';
            $this->line("[{$time}] failed_job #{$row->id}   queue={$row->queue}{$marker}");
            $this->line('  ' . preg_replace('/\s+/', ' ', $excPreview));
        }

        $this->newLine();
    }

    private function probeUrl(string $url): array
    {
        try {
            $resp = Http::timeout(8)->withHeaders(['User-Agent' => 'PrivusBot/blog-debug'])->head($url);
            $status = $resp->status();
            $ctype  = strtolower((string) $resp->header('Content-Type'));
            $short  = trim(explode(';', $ctype)[0]) ?: '?';

            $note = null;
            if ($status >= 400) {
                $note = "URL pública retornou HTTP {$status} — a capa não está sendo servida (storage:link?).";
            } elseif (!str_starts_with($ctype, 'image/')) {
                $note = "URL pública retornou '{$short}' em vez de imagem (storage:link quebrado servindo HTML?).";
            }

            return ['label' => "{$status} {$short}", 'note' => $note];
        } catch (\Throwable $e) {
            return ['label' => 'ERRO', 'note' => 'Falha ao acessar a URL pública: ' . $e->getMessage()];
        }
    }

    private function highlight(array $ctx): string
    {
        $parts = [];

        foreach (['attempt', 'has_content', 'content_length', 'ref_images_count', 'size', 'has_stored_path', 'has_url', 'model', 'path', 'dimensions', 'error', 'new_status'] as $key) {
            if (array_key_exists($key, $ctx) && $ctx[$key] !== null && $ctx[$key] !== '') {
                $val = is_scalar($ctx[$key]) ? (string) $ctx[$key] : json_encode($ctx[$key], JSON_UNESCAPED_SLASHES);
                $parts[] = "{$key}={$val}";
            }
        }

        if (!empty($ctx['prompt_preview'])) {
            $parts[] = 'prompt=' . mb_substr((string) $ctx['prompt_preview'], 0, 140);
        }
        if (!empty($ctx['stored_path'])) {
            $parts[] = 'stored_path=' . $ctx['stored_path'];
        }
        if (!empty($ctx['url_preview'])) {
            $parts[] = 'url=' . mb_substr((string) $ctx['url_preview'], 0, 100);
        }
        if (!empty($ctx['file'])) {
            $parts[] = 'file=' . $ctx['file'];
        }

        return implode('  ', $parts);
    }
}

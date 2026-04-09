<?php

namespace App\Jobs;

use App\Models\BlogArticle;
use App\Models\SystemLog;
use App\Services\Blog\BlogArticleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job dedicado para gerar a imagem de capa de um artigo de blog.
 * Roda em background com retries automáticos para lidar com falhas
 * temporárias da API de geração de imagem.
 */
class GenerateBlogCoverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public int $articleId,
        public ?int $width = null,
        public ?int $height = null,
    ) {}

    public function handle(BlogArticleService $articleService): void
    {
        $article = BlogArticle::find($this->articleId);

        if (!$article) {
            SystemLog::warning('blog', 'cover_job.article_not_found', "Artigo #{$this->articleId} não encontrado para geração de capa");
            return;
        }

        // Já tem capa? Pula
        if (!empty($article->cover_image_path)) {
            return;
        }

        $brand = $article->brand;
        if (!$brand) {
            SystemLog::warning('blog', 'cover_job.no_brand', "Artigo #{$article->id} sem marca associada");
            return;
        }

        SystemLog::info('blog', 'cover_job.start', "Gerando capa para artigo #{$article->id}: {$article->title}", [
            'article_id' => $article->id,
            'attempt' => $this->attempts(),
        ]);

        try {
            $coverResult = $articleService->generateCoverImage(
                brand: $brand,
                title: $article->title,
                excerpt: $article->excerpt ?? '',
                width: $this->width ?? 1750,
                height: $this->height ?? 650,
                content: $article->content ?? '',
                keywords: $article->meta_keywords,
            );

            if ($coverResult && !empty($coverResult['path'])) {
                $article->update(['cover_image_path' => $coverResult['path']]);

                SystemLog::info('blog', 'cover_job.success', "Capa gerada para artigo #{$article->id}", [
                    'article_id' => $article->id,
                    'path' => $coverResult['path'],
                ]);

                // Auto-aprovar se artigo completo e config permite
                $article->refresh();
                $cfg = $brand->getContentEngineConfig();
                if ((bool) ($cfg['blog_auto_approve'] ?? false) && $article->isComplete() && $article->status === 'pending_review') {
                    $article->update(['status' => 'approved']);
                    SystemLog::info('blog', 'cover_job.auto_approved', "Artigo #{$article->id} auto-aprovado após gerar capa", [
                        'article_id' => $article->id,
                    ]);
                }
            } else {
                throw new \RuntimeException('generateCoverImage retornou null/vazio');
            }
        } catch (\Throwable $e) {
            SystemLog::error('blog', 'cover_job.failed', "Falha ao gerar capa para artigo #{$article->id}: {$e->getMessage()}", [
                'article_id' => $article->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            // Re-throw para o Laravel tentar novamente (até $tries vezes)
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        SystemLog::error('blog', 'cover_job.permanent_failure', "Geração de capa falhou permanentemente para artigo #{$this->articleId} após {$this->tries} tentativas: {$e->getMessage()}", [
            'article_id' => $this->articleId,
            'error' => $e->getMessage(),
        ]);
    }
}

<?php

namespace App\Jobs;

use App\Models\AnalyticsConnection;
use App\Models\BlogArticle;
use App\Models\Brand;
use App\Models\SystemLog;
use App\Services\Blog\BlogArticleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;

class GenerateBlogArticlesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(BlogArticleService $articleService): void
    {
        if (!Schema::hasTable('blog_articles')) {
            return;
        }

        SystemLog::info('blog', 'auto_generate.start', 'Iniciando geração automática de artigos de blog');

        $brands = Brand::where('is_active', true)->get();
        $totalGenerated = 0;

        foreach ($brands as $brand) {
            try {
                // Buscar conexões WordPress/WooCommerce ativas da marca
                $connections = AnalyticsConnection::where('brand_id', $brand->id)
                    ->where('is_active', true)
                    ->whereIn('platform', ['wordpress', 'woocommerce'])
                    ->get();

                if ($connections->isEmpty()) {
                    continue;
                }

                foreach ($connections as $connection) {
                    // Verificar se já gerou artigo hoje para esta conexão
                    $todayCount = BlogArticle::where('brand_id', $brand->id)
                        ->where('wordpress_connection_id', $connection->id)
                        ->whereDate('created_at', today())
                        ->count();

                    if ($todayCount >= 2) {
                        continue; // Max 2 artigos por dia por conexão
                    }

                    // Gerar sugestão de tema
                    $topics = $articleService->generateTopicSuggestions($brand, $connection, 1);

                    if (empty($topics)) {
                        SystemLog::warning('blog', 'auto_generate.no_topics', "Sem sugestões para marca #{$brand->id} / conexão #{$connection->id}");
                        continue;
                    }

                    $topic = $topics[0];

                    // Gerar artigo
                    $result = $articleService->generateArticle(
                        brand: $brand,
                        topic: $topic['title'] ?? 'Artigo para ' . $brand->name,
                        keywords: $topic['keywords'] ?? null,
                        wordCount: $topic['estimated_word_count'] ?? 800,
                    );

                    if (!($result['success'] ?? false)) {
                        SystemLog::warning('blog', 'auto_generate.failed', "Falha ao gerar artigo: " . ($result['error'] ?? 'desconhecido'), [
                            'brand_id' => $brand->id,
                            'connection_id' => $connection->id,
                        ]);
                        continue;
                    }

                    // Criar artigo no banco
                    $article = BlogArticle::create([
                        'brand_id' => $brand->id,
                        'user_id' => $brand->users()->first()?->id ?? 1,
                        'wordpress_connection_id' => $connection->id,
                        'title' => $result['title'],
                        'slug' => BlogArticle::generateUniqueSlug($result['title']),
                        'excerpt' => $result['excerpt'] ?? '',
                        'content' => $result['content'] ?? '',
                        'meta_title' => $result['meta_title'] ?? '',
                        'meta_description' => $result['meta_description'] ?? '',
                        'meta_keywords' => $result['meta_keywords'] ?? '',
                        'tags' => $result['tags'] ?? [],
                        'status' => 'pending_review',
                        'ai_model_used' => $result['ai_model_used'] ?? null,
                        'tokens_used' => $result['tokens_used'] ?? 0,
                        'ai_metadata' => [
                            'auto_generated' => true,
                            'topic_suggestion' => $topic,
                            'generated_at' => now()->toISOString(),
                        ],
                    ]);

                    // Tentar gerar imagem de capa
                    $coverResult = $articleService->generateCoverImage(
                        brand: $brand,
                        title: $result['title'],
                        excerpt: $result['excerpt'] ?? '',
                    );

                    if ($coverResult) {
                        $article->update(['cover_image_path' => $coverResult['path']]);
                    }

                    $totalGenerated++;

                    SystemLog::info('blog', 'auto_generate.created', "Artigo gerado: \"{$article->title}\" para marca #{$brand->id}", [
                        'article_id' => $article->id,
                        'brand_id' => $brand->id,
                        'connection_id' => $connection->id,
                        'has_cover' => !empty($coverResult),
                    ]);
                }
            } catch (\Throwable $e) {
                SystemLog::error('blog', 'auto_generate.error', "Erro para marca #{$brand->id}: {$e->getMessage()}", [
                    'brand_id' => $brand->id,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }

        SystemLog::info('blog', 'auto_generate.complete', "Geração automática concluída: {$totalGenerated} artigo(s)");
    }
}

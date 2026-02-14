<?php

namespace App\Services\Blog;

use App\Enums\AIModel;
use App\Models\AnalyticsConnection;
use App\Models\BlogArticle;
use App\Models\Brand;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\AI\AIGateway;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogArticleService
{
    public function __construct(
        private readonly AIGateway $aiGateway,
    ) {}

    /**
     * Gera um artigo completo com IA (título, conteúdo HTML, excerpt, SEO)
     */
    public function generateArticle(
        Brand $brand,
        string $topic,
        ?string $keywords = null,
        ?string $tone = null,
        ?string $instructions = null,
        ?int $wordCount = 800,
        ?User $user = null,
        AIModel $model = AIModel::GPT4oMini,
    ): array {
        $brandContext = $brand->getAIContext();

        $systemPrompt = $this->buildArticleSystemPrompt($brandContext, $tone);
        $userMessage = $this->buildArticleUserMessage($topic, $keywords, $instructions, $wordCount);

        try {
            $response = $this->aiGateway->chat(
                model: $model,
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                brand: $brand,
                user: $user,
                feature: 'blog_article_generation',
                options: ['temperature' => 0.7, 'max_tokens' => 8000],
            );

            $parsed = $this->parseArticleResponse($response['content']);

            $totalTokens = ($response['input_tokens'] ?? 0) + ($response['output_tokens'] ?? 0);

            SystemLog::info('blog', 'article.generated', "Artigo gerado com IA para marca #{$brand->id}: {$parsed['title']}", [
                'brand_id' => $brand->id,
                'topic' => $topic,
                'tokens' => $totalTokens,
                'model' => $model->value,
            ]);

            return [
                'success' => true,
                'title' => $parsed['title'],
                'content' => $parsed['content'],
                'excerpt' => $parsed['excerpt'],
                'meta_title' => $parsed['meta_title'],
                'meta_description' => $parsed['meta_description'],
                'meta_keywords' => $parsed['meta_keywords'],
                'tags' => $parsed['tags'],
                'ai_model_used' => $model->value,
                'tokens_used' => $totalTokens,
            ];
        } catch (\Throwable $e) {
            SystemLog::error('blog', 'article.generation_error', "Erro ao gerar artigo: {$e->getMessage()}", [
                'brand_id' => $brand->id,
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Gera imagem de capa para um artigo com DALL-E 3
     *
     * @param int $width  Largura desejada (default 1750)
     * @param int $height Altura desejada (default 650)
     */
    public function generateCoverImage(
        Brand $brand,
        string $title,
        string $excerpt = '',
        int $width = 1750,
        int $height = 650,
    ): ?array {
        try {
            $prompt = $this->buildCoverImagePrompt($brand, $title, $excerpt);

            // DALL-E 3 só suporta 1024x1024, 1792x1024, 1024x1792
            // Escolher o mais próximo do aspect ratio desejado
            $dalleSize = ($width >= $height) ? '1792x1024' : '1024x1792';

            $result = $this->aiGateway->generateImage(
                prompt: $prompt,
                brand: $brand,
                size: $dalleSize,
                quality: 'standard',
            );

            if (!empty($result['url'])) {
                $imageContent = @file_get_contents($result['url']);
                if ($imageContent) {
                    // Redimensionar para as dimensões exatas desejadas
                    $resized = $this->resizeImage($imageContent, $width, $height);

                    $filename = 'blog-covers/' . uniqid('cover_') . '.png';
                    Storage::disk('public')->put($filename, $resized ?? $imageContent);

                    SystemLog::info('blog', 'cover.generated', "Capa gerada para artigo: {$title} ({$width}x{$height})", [
                        'brand_id' => $brand->id,
                        'path' => $filename,
                        'dimensions' => "{$width}x{$height}",
                        'resized' => $resized !== null,
                    ]);

                    return [
                        'path' => $filename,
                        'url' => Storage::disk('public')->url($filename),
                        'prompt' => $prompt,
                        'width' => $width,
                        'height' => $height,
                    ];
                }
            }

            return null;
        } catch (\Throwable $e) {
            SystemLog::error('blog', 'cover.generation_error', "Erro ao gerar capa: {$e->getMessage()}", [
                'brand_id' => $brand->id,
                'title' => $title,
            ]);
            return null;
        }
    }

    /**
     * Redimensiona uma imagem para as dimensões exatas (crop central)
     */
    private function resizeImage(string $imageData, int $targetWidth, int $targetHeight): ?string
    {
        try {
            $src = @imagecreatefromstring($imageData);
            if (!$src) return null;

            $srcW = imagesx($src);
            $srcH = imagesy($src);

            // Calcular crop para manter aspect ratio desejado (crop central)
            $targetRatio = $targetWidth / $targetHeight;
            $srcRatio = $srcW / $srcH;

            if ($srcRatio > $targetRatio) {
                // Imagem mais larga — cortar laterais
                $cropH = $srcH;
                $cropW = (int) round($srcH * $targetRatio);
                $cropX = (int) round(($srcW - $cropW) / 2);
                $cropY = 0;
            } else {
                // Imagem mais alta — cortar topo/base
                $cropW = $srcW;
                $cropH = (int) round($srcW / $targetRatio);
                $cropX = 0;
                $cropY = (int) round(($srcH - $cropH) / 2);
            }

            // Crop + resize
            $dst = imagecreatetruecolor($targetWidth, $targetHeight);

            // Preservar transparência
            imagealphablending($dst, false);
            imagesavealpha($dst, true);

            imagecopyresampled(
                $dst, $src,
                0, 0, $cropX, $cropY,
                $targetWidth, $targetHeight, $cropW, $cropH
            );

            ob_start();
            imagepng($dst, null, 8);
            $output = ob_get_clean();

            imagedestroy($src);
            imagedestroy($dst);

            return $output ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Gera metadados SEO para um artigo existente
     */
    public function generateSeoMetadata(BlogArticle $article): array
    {
        $brand = $article->brand;
        if (!$brand) {
            return ['success' => false, 'error' => 'Artigo sem marca associada'];
        }

        $systemPrompt = "Você é um especialista em SEO. Gere metadados otimizados para o artigo a seguir.\n"
            . "Responda APENAS em JSON com: meta_title (máx 60 chars), meta_description (máx 160 chars), meta_keywords (string separada por vírgulas).\n"
            . "Contexto da marca: {$brand->name} ({$brand->segment})";

        $userMessage = "Título: {$article->title}\n\n"
            . "Resumo: " . ($article->excerpt ?: Str::limit(strip_tags($article->content), 300)) . "\n\n"
            . "Gere os metadados SEO otimizados em JSON.";

        try {
            $response = $this->aiGateway->chat(
                model: AIModel::GPT4oMini,
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                brand: $brand,
                feature: 'blog_seo_generation',
                options: ['temperature' => 0.4, 'max_tokens' => 500],
            );

            $cleaned = preg_replace('/```json\s*/i', '', $response['content']);
            $cleaned = preg_replace('/```\s*/', '', $cleaned);
            $parsed = json_decode(trim($cleaned), true);

            if (!$parsed) {
                return ['success' => false, 'error' => 'Não foi possível parsear resposta da IA'];
            }

            return [
                'success' => true,
                'meta_title' => $parsed['meta_title'] ?? $article->title,
                'meta_description' => $parsed['meta_description'] ?? '',
                'meta_keywords' => $parsed['meta_keywords'] ?? '',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Gera sugestões de temas de artigos para uma marca
     */
    public function generateTopicSuggestions(Brand $brand, ?AnalyticsConnection $connection = null, int $count = 5): array
    {
        $brandContext = $brand->getAIContext();

        // Buscar artigos recentes para evitar repetição
        $recentArticles = BlogArticle::forBrand($brand->id)
            ->latest()
            ->limit(10)
            ->pluck('title')
            ->toArray();

        $recentList = !empty($recentArticles)
            ? "\n\nArtigos já publicados (NÃO repita estes temas):\n" . implode("\n", array_map(fn($t, $i) => ($i + 1) . ". {$t}", $recentArticles, array_keys($recentArticles)))
            : '';

        // Se tem WooCommerce, incluir produtos populares
        $productsContext = '';
        if ($connection && $connection->platform === 'woocommerce') {
            $productsContext = "\n\nA marca possui uma loja online (WooCommerce). Sugira artigos que possam gerar tráfego e vendas para os produtos.";
        }

        $systemPrompt = "Você é um estrategista de conteúdo digital especializado em SEO e marketing de conteúdo.\n"
            . "Gere {$count} sugestões de artigos de blog para a marca.\n"
            . "Responda APENAS em JSON: array de objetos com { title, keywords (string), description (1 frase), estimated_word_count }.\n"
            . "Foque em temas que:\n"
            . "- Atraiam tráfego orgânico (SEO)\n"
            . "- Sejam relevantes para o público-alvo\n"
            . "- Tenham potencial de conversão\n"
            . "- Variem entre educacional, informativo e comercial\n"
            . "{$productsContext}{$recentList}";

        try {
            $response = $this->aiGateway->chat(
                model: AIModel::GPT4oMini,
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "Marca: {$brand->name}\nSegmento: {$brand->segment}\nPúblico: {$brand->target_audience}\nTom: {$brand->tone_of_voice}\n\nGere {$count} sugestões de artigos."],
                ],
                brand: $brand,
                feature: 'blog_topic_suggestions',
                options: ['temperature' => 0.85, 'max_tokens' => 2000],
            );

            $cleaned = preg_replace('/```json\s*/i', '', $response['content']);
            $cleaned = preg_replace('/```\s*/', '', $cleaned);
            $parsed = json_decode(trim($cleaned), true);

            return is_array($parsed) ? $parsed : [];
        } catch (\Throwable $e) {
            SystemLog::error('blog', 'topic_suggestions.error', "Erro ao gerar sugestões: {$e->getMessage()}", [
                'brand_id' => $brand->id,
            ]);
            return [];
        }
    }

    // ===== PRIVATE =====

    private function buildArticleSystemPrompt(string $brandContext, ?string $tone): string
    {
        $toneInstruction = $tone
            ? "Use o tom de voz: {$tone}."
            : "Use o tom de voz da marca conforme o contexto.";

        return <<<PROMPT
Você é um redator profissional de blog e especialista em SEO.
Gere um artigo completo, bem estruturado e otimizado para mecanismos de busca.

Contexto da marca:
{$brandContext}

Regras:
- {$toneInstruction}
- Use HTML semântico (h2, h3, p, ul, ol, strong, em). NÃO use h1 (o título será separado).
- Estruture com introdução, desenvolvimento em seções (h2/h3) e conclusão
- Use parágrafos curtos (2-3 frases) para melhor leitura web
- Inclua listas quando apropriado para escaneabilidade
- Linguagem natural, evite excesso de palavras-chave (SEO orgânico)
- Conteúdo original e informativo

Responda OBRIGATORIAMENTE neste formato JSON:
{
  "title": "Título do artigo (50-60 caracteres idealmente)",
  "content": "<h2>...</h2><p>...</p>... (HTML completo do artigo)",
  "excerpt": "Resumo em 1-2 frases (máx 200 chars)",
  "meta_title": "Título SEO (máx 60 chars)",
  "meta_description": "Meta descrição (120-160 chars)",
  "meta_keywords": "keyword1, keyword2, keyword3",
  "tags": ["tag1", "tag2", "tag3"]
}
PROMPT;
    }

    private function buildArticleUserMessage(string $topic, ?string $keywords, ?string $instructions, ?int $wordCount): string
    {
        $message = "Escreva um artigo sobre: {$topic}\n";

        if ($keywords) {
            $message .= "Palavras-chave foco: {$keywords}\n";
        }

        if ($instructions) {
            $message .= "Instruções adicionais: {$instructions}\n";
        }

        $message .= "Tamanho desejado: aproximadamente {$wordCount} palavras.\n";
        $message .= "\nGere o artigo completo em JSON conforme o formato solicitado.";

        return $message;
    }

    private function buildCoverImagePrompt(Brand $brand, string $title, string $excerpt): string
    {
        $segment = $brand->segment ?? 'negócio';
        $colors = "cores predominantes: {$brand->primary_color} e {$brand->secondary_color}";

        return "Create a professional, modern blog cover image for an article titled \"{$title}\". "
            . "The image should be clean, visually appealing, and suitable for a {$segment} brand. "
            . "Style: modern, minimalist, professional. {$colors}. "
            . "Do NOT include any text or words in the image. "
            . "The image should convey the essence of the article topic through visual metaphors and composition. "
            . ($excerpt ? "Article context: {$excerpt}" : '');
    }

    private function parseArticleResponse(string $content): array
    {
        // Remover markdown code blocks
        $cleaned = preg_replace('/```json\s*/i', '', $content);
        $cleaned = preg_replace('/```\s*/', '', $cleaned);
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);

        if (is_array($parsed) && !empty($parsed['title'])) {
            return [
                'title' => $parsed['title'] ?? 'Artigo sem título',
                'content' => $parsed['content'] ?? '',
                'excerpt' => $parsed['excerpt'] ?? '',
                'meta_title' => $parsed['meta_title'] ?? $parsed['title'] ?? '',
                'meta_description' => $parsed['meta_description'] ?? '',
                'meta_keywords' => $parsed['meta_keywords'] ?? '',
                'tags' => $parsed['tags'] ?? [],
            ];
        }

        // Fallback: tratar como conteúdo HTML puro
        $title = 'Artigo Gerado';
        if (preg_match('/<h[12][^>]*>(.*?)<\/h[12]>/i', $content, $matches)) {
            $title = strip_tags($matches[1]);
        }

        return [
            'title' => $title,
            'content' => $content,
            'excerpt' => Str::limit(strip_tags($content), 200),
            'meta_title' => Str::limit($title, 60),
            'meta_description' => Str::limit(strip_tags($content), 160),
            'meta_keywords' => '',
            'tags' => [],
        ];
    }
}

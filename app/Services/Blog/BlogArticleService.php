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
     * Gera imagem de capa para um artigo usando gpt-image-2 via AIGateway.
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
        ?string $content = null,
        ?string $keywords = null,
    ): ?array {
        try {
            SystemLog::info('blog', 'cover.step1_start', "[COVER DEBUG] Iniciando geração de capa para: {$title}", [
                'brand_id' => $brand->id,
                'width' => $width,
                'height' => $height,
                'has_content' => !empty($content),
                'content_length' => mb_strlen($content ?? ''),
                'has_keywords' => !empty($keywords),
            ]);

            $prompt = $this->buildCoverImagePrompt($brand, $title, $excerpt, $content, $keywords);

            SystemLog::info('blog', 'cover.step2_prompt', "[COVER DEBUG] Prompt construído (" . mb_strlen($prompt) . " chars)", [
                'brand_id' => $brand->id,
                'prompt_preview' => mb_substr($prompt, 0, 300),
            ]);

            // gpt-image-2 aceita 1024x1024, 1024x1536, 1536x1024 (o AIGateway remapeia)
            $dalleSize = ($width >= $height) ? '1792x1024' : '1024x1792';

            // Referências visuais da marca (sem logo — política no-logo)
            $brandRefImages = $this->extractBrandLogoAndReferences($brand);

            SystemLog::info('blog', 'cover.step3_refs', "[COVER DEBUG] Referências extraídas", [
                'brand_id' => $brand->id,
                'ref_images_count' => count($brandRefImages),
                'size' => $dalleSize,
            ]);

            SystemLog::info('blog', 'cover.step4_calling_api', "[COVER DEBUG] Chamando AIGateway.generateImage...", [
                'brand_id' => $brand->id,
                'size' => $dalleSize,
                'has_refs' => !empty($brandRefImages),
            ]);

            $result = $this->aiGateway->generateImage(
                prompt: $prompt,
                brand: $brand,
                size: $dalleSize,
                quality: 'standard',
                referenceImages: $brandRefImages ?: null,
            );

            SystemLog::info('blog', 'cover.step5_api_response', "[COVER DEBUG] AIGateway respondeu", [
                'brand_id' => $brand->id,
                'has_stored_path' => !empty($result['stored_path']),
                'stored_path' => $result['stored_path'] ?? null,
                'has_url' => !empty($result['url']),
                'url_preview' => mb_substr($result['url'] ?? '', 0, 100),
                'model' => $result['model'] ?? null,
            ]);

            // Buscar imagem gerada: priorizar stored_path (já salvo no storage), fallback para URL
            $imageContent = null;

            if (!empty($result['stored_path'])) {
                $imageContent = Storage::disk('public')->get($result['stored_path']);
                SystemLog::info('blog', 'cover.step6a_stored', "[COVER DEBUG] Imagem lida do storage: " . ($imageContent ? strlen($imageContent) . ' bytes' : 'FALHOU'), [
                    'brand_id' => $brand->id,
                    'path' => $result['stored_path'],
                ]);
            }

            if (!$imageContent && !empty($result['url'])) {
                $imageContent = @file_get_contents($result['url']);
                SystemLog::info('blog', 'cover.step6b_url', "[COVER DEBUG] Imagem baixada da URL: " . ($imageContent ? strlen($imageContent) . ' bytes' : 'FALHOU'), [
                    'brand_id' => $brand->id,
                    'url' => mb_substr($result['url'], 0, 100),
                ]);
            }

            if ($imageContent) {
                // Redimensionar para as dimensões exatas desejadas
                $resized = $this->resizeImage($imageContent, $width, $height);

                $filename = 'blog-covers/' . uniqid('cover_') . '.png';
                Storage::disk('public')->put($filename, $resized ?? $imageContent);

                // Limpar imagem temporária original se foi salva em ai-generated/
                if (!empty($result['stored_path']) && $result['stored_path'] !== $filename) {
                    Storage::disk('public')->delete($result['stored_path']);
                }

                SystemLog::info('blog', 'cover.step7_success', "[COVER DEBUG] Capa salva com sucesso: {$filename} ({$width}x{$height})", [
                    'brand_id' => $brand->id,
                    'path' => $filename,
                    'dimensions' => "{$width}x{$height}",
                    'resized' => $resized !== null,
                    'final_size' => strlen($resized ?? $imageContent),
                ]);

                return [
                    'path' => $filename,
                    'url' => Storage::disk('public')->url($filename),
                    'prompt' => $prompt,
                    'width' => $width,
                    'height' => $height,
                ];
            }

            SystemLog::error('blog', 'cover.step7_no_content', "[COVER DEBUG] Nenhum conteúdo de imagem obtido — retornando null", [
                'brand_id' => $brand->id,
                'had_stored_path' => !empty($result['stored_path']),
                'had_url' => !empty($result['url']),
            ]);

            return null;
        } catch (\Throwable $e) {
            SystemLog::error('blog', 'cover.generation_error', "[COVER DEBUG] EXCEPTION: {$e->getMessage()}", [
                'brand_id' => $brand->id,
                'title' => $title,
                'exception_class' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace_preview' => mb_substr($e->getTraceAsString(), 0, 500),
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

    /**
     * Extrai referências visuais da marca (estilo/cor/composição) como base64 para enviar à API de imagem.
     * Política no-logo: o logo NÃO é incluído como referência para não ser renderizado na capa.
     */
    private function extractBrandLogoAndReferences(Brand $brand): array
    {
        $images = [];

        $references = $brand->references()->limit(3)->get();
        foreach ($references as $ref) {
            $path = Storage::disk('public')->path($ref->file_path);
            if (!file_exists($path)) continue;
            if (filesize($path) > 5 * 1024 * 1024) continue;
            $images[] = [
                'base64' => base64_encode(file_get_contents($path)),
                'mime' => $ref->mime_type ?? 'image/jpeg',
                'role' => 'reference',
            ];
        }

        return $images;
    }

    private function buildArticleSystemPrompt(string $brandContext, ?string $tone): string
    {
        $toneInstruction = $tone
            ? "Use o tom de voz: {$tone}."
            : "Use o tom de voz da marca conforme o contexto.";

        return <<<PROMPT
Você é um redator profissional de blog e especialista em SEO on-page (Google Helpful Content + E-E-A-T).
Gere um artigo COMPLETO, original e otimizado para ranquear.

Contexto da marca:
{$brandContext}

DIRETRIZES DE SEO ON-PAGE (críticas):
1. **Focus keyword**: a primeira palavra-chave informada é o "focus keyword". Inclua-a:
   - No título (preferencialmente nos primeiros 60% do título)
   - Nos primeiros 100 caracteres do corpo (1º parágrafo)
   - Em pelo menos 1 H2
   - No meta_title e meta_description
   - 2-4 vezes ao longo do conteúdo (densidade ~1%, NUNCA force)
2. **Variações semânticas (LSI)**: use sinônimos e termos relacionados naturalmente.
3. **Intenção de busca**: identifique se o tema é informacional/comparativo/transacional e ajuste profundidade e CTA.
4. **Slug-friendly title**: títulos curtos, claros, com a keyword principal — sem clickbait.

ESTRUTURA OBRIGATÓRIA DO CONTEÚDO (HTML):
- {$toneInstruction}
- Use HTML semântico: h2, h3, p, ul, ol, strong, em, blockquote. NÃO use h1 (o título é separado).
- Abertura (1-2 parágrafos): apresenta o problema/promessa e contém o focus keyword nos primeiros 100 caracteres.
- Desenvolvimento: 3-6 seções com H2 descritivos (cada H2 deve passar a ideia mesmo lido isoladamente). Use H3 quando precisar subdividir.
- Inclua pelo menos 1 lista (ul ou ol) e use <strong> para destacar 2-4 termos-chave (incluindo o focus keyword 1x).
- Conclusão (h2 "Conclusão" ou similar): resumo em 2-3 frases + CTA suave alinhado à marca.
- Quando o tema for "como fazer", "o que é", "diferença entre" — adicione uma seção FAQ no final (h2 "Perguntas frequentes" + h3 com cada pergunta + p com resposta de 2-3 frases). Isso captura rich snippets.
- Parágrafos curtos (2-4 linhas), linguagem natural, sem keyword stuffing.
- Conteúdo original, informativo e com profundidade suficiente para responder a intenção de busca.

REGRAS DE METADADOS:
- title: 50-60 caracteres, claro, com focus keyword no começo quando possível
- meta_title: pode ser igual ao title ou variação — máx 60 chars
- meta_description: 140-160 chars, persuasiva, com focus keyword e CTA implícito
- meta_keywords: 3-6 termos separados por vírgula. O PRIMEIRO é o focus keyword.
- tags: 3-6 tags em minúsculas, separadas, sem espaços extras
- excerpt: 1-2 frases (máx 200 chars), reescreva — não copie a abertura

Responda OBRIGATORIAMENTE neste formato JSON (apenas JSON, sem markdown):
{
  "title": "Título com focus keyword (50-60 chars)",
  "content": "<p>Abertura com focus keyword nos primeiros 100 chars...</p><h2>...</h2><p>...</p><ul><li>...</li></ul><h2>Perguntas frequentes</h2><h3>...</h3><p>...</p><h2>Conclusão</h2><p>...</p>",
  "excerpt": "Resumo único em 1-2 frases (máx 200 chars).",
  "meta_title": "Título SEO ≤ 60 chars com focus keyword",
  "meta_description": "Meta descrição persuasiva 140-160 chars com focus keyword e CTA implícito.",
  "meta_keywords": "focus keyword, sinonimo1, termo relacionado, intencao",
  "tags": ["tag1", "tag2", "tag3"]
}
PROMPT;
    }

    private function buildArticleUserMessage(string $topic, ?string $keywords, ?string $instructions, ?int $wordCount): string
    {
        // Identificar focus keyword (primeira da lista)
        $focusKeyword = null;
        if ($keywords) {
            $parts = array_map('trim', explode(',', $keywords));
            $focusKeyword = $parts[0] ?? null;
        }

        $message = "Tema do artigo: {$topic}\n";

        if ($focusKeyword) {
            $message .= "Focus keyword (use conforme as diretrizes): \"{$focusKeyword}\"\n";
        }
        if ($keywords) {
            $message .= "Palavras-chave (a 1ª é o focus): {$keywords}\n";
        }

        if ($instructions) {
            $message .= "Instruções adicionais: {$instructions}\n";
        }

        // Garantir tamanho mínimo competitivo para SEO (Google favorece conteúdo aprofundado)
        $minTarget = max($wordCount ?? 800, 900);
        $message .= "Tamanho-alvo: {$minTarget}-" . ($minTarget + 400) . " palavras (não corte ideias para encurtar).\n";
        $message .= "\nGere o artigo completo em JSON, seguindo TODAS as diretrizes de SEO on-page.";

        return $message;
    }

    private function buildCoverImagePrompt(Brand $brand, string $title, string $excerpt, ?string $content = null, ?string $keywords = null): string
    {
        $segment = $brand->segment ?? 'negócio';

        // Sanitizar nome da marca de todos os textos para evitar hallucination de logo pelo modelo
        $cleanTitle = \App\Services\AI\AIGateway::stripBrandName($title, $brand->name);
        $cleanExcerpt = \App\Services\AI\AIGateway::stripBrandName($excerpt, $brand->name);
        $cleanContent = $content ? \App\Services\AI\AIGateway::stripBrandName($content, $brand->name) : null;

        $prompt = "Create a REALISTIC, photographic-style blog cover image for a Brazilian {$segment} article. ";
        $prompt .= "The image MUST be visually relevant to the SPECIFIC article topic — not generic. ";
        $prompt .= "Article title (in Portuguese): \"{$cleanTitle}\". ";
        $prompt .= "The image must look like a real professional photograph — NOT an illustration, NOT a cartoon, NOT digital art, NOT random imagery. ";

        if ($brand->primary_color) {
            $prompt .= "Color palette: {$brand->primary_color}";
            if ($brand->secondary_color) $prompt .= " and {$brand->secondary_color}";
            $prompt .= " — use subtly in lighting, background tones, or props. ";
        }

        // Contexto de produtos para imagens mais relevantes
        $products = $brand->products()->limit(3)->get();
        if ($products->isNotEmpty()) {
            $productNames = $products->pluck('label')->implode(', ');
            $prompt .= "The business sells: {$productNames}. Include relevant generic products or similar items in the composition when the topic relates to them — without ANY brand identification. ";
        }

        $prompt .= "Style: clean editorial photography, natural lighting, real textures, warm tones, professional studio quality. ";
        $prompt .= "Think: magazine cover photo, editorial spread, lifestyle product shot. ";
        $prompt .= "Do NOT include any text or words in the image — text will be added separately as overlay. ";

        // CRÍTICO: contexto do artigo para imagem relevante
        if ($cleanExcerpt) {
            $prompt .= "\n\nARTICLE EXCERPT (in Portuguese — use this to make the image relevant): \"" . mb_substr($cleanExcerpt, 0, 300) . "\". ";
        }

        if ($keywords) {
            $prompt .= "Article keywords: {$keywords}. The image should visually represent these concepts. ";
        }

        if ($cleanContent) {
            // Extrair primeiros parágrafos do conteúdo (sem HTML) para dar contexto rico à IA
            $plainContent = strip_tags($cleanContent);
            $plainContent = preg_replace('/\s+/', ' ', $plainContent);
            $contentSnippet = mb_substr(trim($plainContent), 0, 600);
            if ($contentSnippet) {
                $prompt .= "\n\nARTICLE CONTENT (first paragraphs, in Portuguese — the image MUST visually represent the actual subject discussed here): \"{$contentSnippet}\". ";
                $prompt .= "Analyze this content carefully and create an image that illustrates the SPECIFIC topic, scenario, objects, people, or environment mentioned. Do NOT generate a generic or random image — it must clearly relate to the article's actual subject matter.";
            }
        }

        return $prompt;
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

<?php

namespace App\Services\Blog;

use App\Enums\AIModel;
use App\Models\BlogArticle;
use App\Models\BlogCalendarItem;
use App\Models\Brand;
use App\Models\SystemLog;
use App\Services\AI\AIGateway;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class BlogCalendarService
{
    public function __construct(
        private readonly AIGateway $aiGateway,
        private readonly BlogArticleService $articleService,
    ) {}

    /**
     * Gera pautas de blog com IA para um período (calendário editorial)
     */
    public function generateCalendar(
        Brand $brand,
        int $userId,
        string $startDate,
        string $endDate,
        array $options = [],
    ): array {
        $postsPerWeek = $options['posts_per_week'] ?? 2;
        $tone = $options['tone'] ?? $brand->tone_of_voice ?? 'profissional e acessível';
        $aiModel = $options['ai_model'] ?? 'gpt-4o-mini';
        $extraInstructions = $options['instructions'] ?? '';
        $batchStatus = $options['batch_status'] ?? 'draft';
        $connectionId = $options['wordpress_connection_id'] ?? null;
        $categoryId = $options['blog_category_id'] ?? null;

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $totalDays = $start->diffInDays($end) + 1;
        $totalWeeks = max(1, ceil($totalDays / 7));
        $totalArticles = intval($totalWeeks * $postsPerWeek);

        // Artigos recentes (evitar repetição)
        $recentArticles = BlogArticle::forBrand($brand->id)
            ->latest()
            ->limit(15)
            ->pluck('title')
            ->filter()
            ->map(fn($t) => '- ' . $t)
            ->implode("\n");

        // Pautas já existentes no período
        $existingItems = BlogCalendarItem::where('brand_id', $brand->id)
            ->whereBetween('scheduled_date', [$startDate, $endDate])
            ->pluck('title')
            ->map(fn($t) => '- ' . $t)
            ->implode("\n");

        $brandContext = $brand->getAIContext();
        $batchId = uniqid('blogcal_');

        $prompt = $this->buildCalendarPrompt(
            $brandContext, $start->format('Y-m-d'), $end->format('Y-m-d'),
            $totalArticles, $tone, $recentArticles, $existingItems, $extraInstructions
        );

        try {
            $model = AIModel::from($aiModel);

            $response = $this->aiGateway->chat(
                model: $model,
                messages: [
                    ['role' => 'system', 'content' => $prompt],
                    ['role' => 'user', 'content' => "Gere o calendário editorial de blog para a marca \"{$brand->name}\" ({$brand->segment}) de {$start->format('d/m/Y')} a {$end->format('d/m/Y')} com {$totalArticles} artigos distribuídos."],
                ],
                brand: $brand,
                feature: 'blog_calendar',
                options: ['temperature' => 0.85, 'max_tokens' => 6000],
            );

            $items = $this->parseCalendarResponse($response['content']);

            if (empty($items)) {
                SystemLog::warning('blog', 'calendar.parse_failed', 'IA retornou formato inválido para calendário de blog');
                return ['success' => false, 'error' => 'A IA retornou um formato inválido. Tente novamente.', 'items' => [], 'batch_id' => $batchId];
            }

            $totalTokens = ($response['input_tokens'] ?? 0) + ($response['output_tokens'] ?? 0);
            $created = [];

            foreach ($items as $item) {
                $date = $item['date'] ?? null;
                if (!$date) continue;

                try {
                    $parsedDate = Carbon::parse($date);
                    if ($parsedDate->lt($start) || $parsedDate->gt($end)) continue;
                } catch (\Exception $e) {
                    continue;
                }

                $calendarItem = BlogCalendarItem::create([
                    'brand_id' => $brand->id,
                    'user_id' => $userId,
                    'scheduled_date' => $parsedDate->format('Y-m-d'),
                    'title' => $item['title'] ?? 'Artigo sem título',
                    'description' => $item['description'] ?? '',
                    'keywords' => $item['keywords'] ?? '',
                    'tone' => $item['tone'] ?? $tone,
                    'instructions' => $item['instructions'] ?? '',
                    'estimated_word_count' => $item['estimated_word_count'] ?? 800,
                    'wordpress_connection_id' => $connectionId,
                    'blog_category_id' => $categoryId,
                    'status' => 'pending',
                    'ai_model_used' => $aiModel,
                    'batch_id' => $batchId,
                    'batch_status' => $batchStatus,
                    'metadata' => [
                        'generated_at' => now()->toISOString(),
                        'tokens_used' => $totalTokens,
                    ],
                ]);

                $created[] = $calendarItem;
            }

            SystemLog::info('blog', 'calendar.generated', "Calendário de blog gerado: {$brand->name} — " . count($created) . " pautas ({$start->format('d/m')} a {$end->format('d/m')})", [
                'brand_id' => $brand->id,
                'batch_id' => $batchId,
                'total_items' => count($created),
                'tokens' => $totalTokens,
            ]);

            return [
                'success' => true,
                'items' => $created,
                'batch_id' => $batchId,
                'total' => count($created),
                'tokens_used' => $totalTokens,
            ];
        } catch (\Throwable $e) {
            SystemLog::error('blog', 'calendar.generation_error', "Erro ao gerar calendário: {$e->getMessage()}", [
                'brand_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'items' => [], 'batch_id' => $batchId];
        }
    }

    /**
     * Gera artigo completo a partir de uma pauta do calendário
     */
    public function generateArticleFromItem(BlogCalendarItem $item): array
    {
        if (!$item->canGenerateArticle()) {
            return ['success' => false, 'error' => 'Esta pauta não pode gerar artigo no status atual.'];
        }

        $brand = $item->brand;
        if (!$brand) {
            return ['success' => false, 'error' => 'Pauta sem marca associada.'];
        }

        $item->update(['status' => 'generating']);

        try {
            // 1. Gerar artigo com IA
            $result = $this->articleService->generateArticle(
                brand: $brand,
                topic: $item->title,
                keywords: $item->keywords,
                tone: $item->tone,
                instructions: $item->description . ($item->instructions ? "\n" . $item->instructions : ''),
                wordCount: $item->estimated_word_count ?? 800,
            );

            if (!($result['success'] ?? false)) {
                $item->update(['status' => 'pending']);
                return ['success' => false, 'error' => $result['error'] ?? 'Falha na geração do artigo.'];
            }

            // 2. Criar BlogArticle
            $article = BlogArticle::create([
                'brand_id' => $brand->id,
                'user_id' => $item->user_id,
                'wordpress_connection_id' => $item->wordpress_connection_id,
                'blog_category_id' => $item->blog_category_id,
                'title' => $result['title'],
                'slug' => BlogArticle::generateUniqueSlug($result['title']),
                'excerpt' => $result['excerpt'] ?? '',
                'content' => $result['content'] ?? '',
                'meta_title' => $result['meta_title'] ?? '',
                'meta_description' => $result['meta_description'] ?? '',
                'meta_keywords' => $result['meta_keywords'] ?? '',
                'tags' => $result['tags'] ?? [],
                'status' => 'pending_review',
                'scheduled_publish_at' => $item->scheduled_date,
                'ai_model_used' => $result['ai_model_used'] ?? null,
                'tokens_used' => $result['tokens_used'] ?? 0,
                'ai_metadata' => [
                    'calendar_item_id' => $item->id,
                    'generated_from_calendar' => true,
                    'original_topic' => $item->title,
                ],
            ]);

            // 3. Tentar gerar imagem de capa
            $coverResult = $this->articleService->generateCoverImage(
                brand: $brand,
                title: $result['title'],
                excerpt: $result['excerpt'] ?? '',
            );

            if ($coverResult) {
                $article->update(['cover_image_path' => $coverResult['path']]);
            }

            // 4. Vincular artigo à pauta
            $item->update([
                'status' => 'generated',
                'article_id' => $article->id,
            ]);

            SystemLog::info('blog', 'calendar.article_generated', "Artigo gerado da pauta: \"{$item->title}\"", [
                'calendar_item_id' => $item->id,
                'article_id' => $article->id,
                'has_cover' => !empty($coverResult),
            ]);

            return [
                'success' => true,
                'article_id' => $article->id,
                'article_title' => $article->title,
                'has_cover' => !empty($coverResult),
            ];
        } catch (\Throwable $e) {
            $item->update(['status' => 'pending']);

            SystemLog::error('blog', 'calendar.article_generation_error', "Erro ao gerar artigo da pauta #{$item->id}: {$e->getMessage()}", [
                'calendar_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Gera artigos para todas as pautas pendentes/aprovadas no período
     */
    public function generateArticlesForPendingItems(
        int $brandId,
        string $startDate,
        string $endDate,
        int $limit = 10,
    ): array {
        $items = BlogCalendarItem::where('brand_id', $brandId)
            ->where('status', 'pending')
            ->approvedOrNoBatch()
            ->forDateRange($startDate, $endDate)
            ->where('scheduled_date', '>=', now()->subDay()->format('Y-m-d'))
            ->orderBy('scheduled_date')
            ->limit($limit)
            ->get();

        $results = ['generated' => 0, 'failed' => 0, 'errors' => []];

        foreach ($items as $item) {
            $result = $this->generateArticleFromItem($item);
            if ($result['success'] ?? false) {
                $results['generated']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'item_id' => $item->id,
                    'title' => $item->title,
                    'error' => $result['error'] ?? 'Desconhecido',
                ];
            }
        }

        return $results;
    }

    // ===== PRIVATE =====

    private function buildCalendarPrompt(
        string $brandContext,
        string $startDate,
        string $endDate,
        int $totalArticles,
        string $tone,
        string $recentArticles,
        string $existingItems,
        string $extraInstructions,
    ): string {
        $avoidSection = '';
        if ($recentArticles) {
            $avoidSection .= "\nArtigos já publicados (NÃO repita estes temas):\n{$recentArticles}\n";
        }
        if ($existingItems) {
            $avoidSection .= "\nPautas já existentes no período (NÃO duplique):\n{$existingItems}\n";
        }

        $instructionSection = $extraInstructions ? "\nInstruções adicionais do usuário: {$extraInstructions}\n" : '';

        return <<<PROMPT
Você é um estrategista de conteúdo digital especializado em SEO e marketing de conteúdo para blogs.

Gere um calendário editorial de blog com exatamente {$totalArticles} artigos distribuídos uniformemente entre {$startDate} e {$endDate}.

Contexto da marca:
{$brandContext}

Tom de voz: {$tone}
{$avoidSection}{$instructionSection}
Regras para o calendário:
- Distribua os artigos uniformemente ao longo das semanas (NÃO concentre tudo em poucos dias)
- Prefira publicações em dias úteis (segunda a sexta)
- Varie os temas entre: educacional, tutorial, lista, comparativo, estudo de caso, notícia/tendência, guia completo
- Foque em temas com potencial de tráfego orgânico (SEO)
- Cada artigo deve ter palavras-chave claras e estimativa de tamanho realista
- Varie o tamanho: artigos curtos (400-600), médios (800-1200), e longos (1500-2500)
- NÃO repita temas dos artigos já listados acima

Responda OBRIGATORIAMENTE como um JSON array:
[
  {
    "date": "YYYY-MM-DD",
    "title": "Título do artigo (claro e SEO-friendly)",
    "description": "Briefing de 1-2 frases sobre o que abordar",
    "keywords": "keyword1, keyword2, keyword3",
    "tone": "tom específico para este artigo",
    "instructions": "instruções especiais ou pontos a cobrir",
    "estimated_word_count": 800
  }
]

Retorne APENAS o JSON, sem texto extra.
PROMPT;
    }

    private function parseCalendarResponse(string $content): array
    {
        // Remover markdown code blocks
        $cleaned = preg_replace('/```json\s*/i', '', $content);
        $cleaned = preg_replace('/```\s*/', '', $cleaned);
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);

        if (is_array($parsed) && !empty($parsed)) {
            // Pode ser array de objetos diretamente ou { items: [...] }
            if (isset($parsed['items']) && is_array($parsed['items'])) {
                return $parsed['items'];
            }

            // Verificar se o primeiro elemento tem 'date'
            if (isset($parsed[0]['date'])) {
                return $parsed;
            }
        }

        // Tentar extrair JSON de dentro do texto
        if (preg_match('/\[[\s\S]*\]/', $cleaned, $matches)) {
            $inner = json_decode($matches[0], true);
            if (is_array($inner) && !empty($inner)) {
                return $inner;
            }
        }

        return [];
    }
}

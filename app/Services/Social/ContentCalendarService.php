<?php

namespace App\Services\Social;

use App\Enums\AIModel;
use App\Models\Brand;
use App\Models\ContentCalendarItem;
use App\Models\ContentSuggestion;
use App\Models\Post;
use App\Models\SystemLog;
use App\Services\AI\AIGateway;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Serviço para geração de calendários de conteúdo com IA
 * e conversão de pautas em posts para aprovação.
 */
class ContentCalendarService
{
    public function __construct(
        private readonly AIGateway $aiGateway,
        private readonly BrandIntelligenceService $intelligenceService,
    ) {}

    /**
     * Gera um calendário de conteúdo com IA para um período específico.
     */
    public function generateCalendar(
        Brand $brand,
        int $userId,
        string $startDate,
        string $endDate,
        array $options = []
    ): array {
        $brandContext = $brand->getAIContext();
        $postsPerWeek = $options['posts_per_week'] ?? 5;
        $platforms = $options['platforms'] ?? ['instagram'];
        $categories = $options['categories'] ?? [];
        $tone = $options['tone'] ?? $brand->tone_of_voice ?? 'profissional e acessivel';
        $aiModel = $options['ai_model'] ?? 'gemini-2.0-flash';
        $extraInstructions = $options['instructions'] ?? '';
        $batchStatus = $options['batch_status'] ?? null; // 'draft' para geracao automatica, null para manual
        $formatMode = $options['format_mode'] ?? 'auto'; // 'auto' ou 'manual'
        $postTypes = $options['post_types'] ?? []; // formatos selecionados no modo manual

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $totalDays = $start->diffInDays($end) + 1;
        $totalWeeks = ceil($totalDays / 7);
        $totalPosts = intval($totalWeeks * $postsPerWeek);

        // Gerar relatorio de inteligencia (redes sociais, analytics, e-commerce, historico)
        $intelligenceReport = $this->intelligenceService->buildIntelligenceReport($brand);

        // Buscar posts recentes para evitar repetição
        $recentPosts = $brand->posts()
            ->latest()
            ->limit(10)
            ->pluck('caption')
            ->filter()
            ->map(fn($c) => mb_substr($c, 0, 80))
            ->implode("\n");

        // Buscar itens de calendário já existentes no período
        $existingItems = ContentCalendarItem::where('brand_id', $brand->id)
            ->whereBetween('scheduled_date', [$startDate, $endDate])
            ->pluck('scheduled_date')
            ->map(fn($d) => Carbon::parse($d)->format('Y-m-d'))
            ->toArray();

        $platformsStr = implode(', ', $platforms);
        $categoriesStr = !empty($categories) ? implode(', ', $categories) : 'dica, novidade, bastidores, promocao, educativo, inspiracional, engajamento, produto, institucional, depoimento, lancamento, tendencia';

        // Preparar instrucao de formato de conteudo
        $formatInstruction = '';
        if ($formatMode === 'manual' && !empty($postTypes)) {
            $typesStr = implode(', ', $postTypes);
            $formatInstruction = "IMPORTANTE: Use SOMENTE os seguintes formatos de conteudo: {$typesStr}. Distribua os posts de forma equilibrada entre esses formatos.";
        } else {
            $formatInstruction = "Escolha automaticamente o melhor formato de conteudo (feed, carousel, reel, story, video, pin) para cada post, baseado nos dados de performance e na estrategia. Priorize formatos com melhor engajamento historico.";
        }

        $prompt = $this->buildCalendarPrompt(
            $brandContext, $start->format('Y-m-d'), $end->format('Y-m-d'),
            $totalPosts, $platformsStr, $categoriesStr, $tone,
            $recentPosts, $existingItems, $extraInstructions, $intelligenceReport,
            $formatInstruction
        );

        $batchId = uniqid('cal_');

        try {
            $model = AIModel::from($aiModel);

            $response = $this->aiGateway->chat(
                model: $model,
                messages: [
                    ['role' => 'system', 'content' => $prompt],
                    ['role' => 'user', 'content' => "Gere o calendario de conteudo para a marca \"{$brand->name}\" ({$brand->segment}) de {$start->format('d/m/Y')} a {$end->format('d/m/Y')} com {$totalPosts} posts distribuidos."],
                ],
                feature: 'content_calendar',
                options: ['temperature' => 0.85, 'max_tokens' => 8000],
            );

            $items = $this->parseCalendarResponse($response['content']);

            if (empty($items)) {
                SystemLog::warning('content', 'calendar.parse_failed', 'IA retornou formato invalido para calendario');
                return ['success' => false, 'error' => 'A IA retornou um formato invalido. Tente novamente.', 'items' => [], 'batch_id' => $batchId];
            }

            $totalTokens = ($response['input_tokens'] ?? 0) + ($response['output_tokens'] ?? 0);
            $created = [];

            foreach ($items as $item) {
                $date = $item['date'] ?? null;
                if (!$date) continue;

                // Validar que a data está no range
                try {
                    $parsedDate = Carbon::parse($date);
                    if ($parsedDate->lt($start) || $parsedDate->gt($end)) continue;
                } catch (\Exception $e) {
                    continue;
                }

                $calendarItem = ContentCalendarItem::create([
                    'brand_id' => $brand->id,
                    'user_id' => $userId,
                    'scheduled_date' => $date,
                    'title' => $item['title'] ?? 'Post ' . $parsedDate->format('d/m'),
                    'description' => $item['description'] ?? null,
                    'category' => $item['category'] ?? 'geral',
                    'platforms' => $item['platforms'] ?? $platforms,
                    'post_type' => $item['post_type'] ?? 'feed',
                    'tone' => $item['tone'] ?? $tone,
                    'instructions' => $item['instructions'] ?? null,
                    'status' => 'pending',
                    'ai_model_used' => $aiModel,
                    'batch_id' => $batchId,
                    'batch_status' => $batchStatus,
                    'metadata' => [
                        'generated_at' => now()->toIso8601String(),
                        'tokens_used' => intval($totalTokens / max(count($items), 1)),
                        'has_intelligence' => !empty($intelligenceReport),
                    ],
                ]);

                $created[] = $calendarItem;
            }

            SystemLog::info('content', 'calendar.generated', "Calendario gerado: " . count($created) . " itens para marca #{$brand->id}" . ($batchStatus ? " (status: {$batchStatus})" : ''), [
                'brand_id' => $brand->id,
                'period' => "{$startDate} a {$endDate}",
                'total_items' => count($created),
                'total_tokens' => $totalTokens,
                'batch_id' => $batchId,
                'batch_status' => $batchStatus,
                'has_intelligence' => !empty($intelligenceReport),
            ]);

            return [
                'success' => true,
                'items' => $created,
                'total' => count($created),
                'tokens_used' => $totalTokens,
                'batch_id' => $batchId,
            ];

        } catch (\Throwable $e) {
            SystemLog::error('content', 'calendar.error', "Erro ao gerar calendario: {$e->getMessage()}", [
                'brand_id' => $brand->id,
                'exception' => get_class($e),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'items' => [], 'batch_id' => $batchId];
        }
    }

    /**
     * Gera post a partir de um item do calendário (pauta).
     * Retorna uma ContentSuggestion com status "pending" para aprovação.
     */
    public function generatePostFromItem(ContentCalendarItem $item): ?ContentSuggestion
    {
        $brand = $item->brand;
        if (!$brand) return null;

        $brandContext = $brand->getAIContext();
        $platform = $item->platforms[0] ?? 'instagram';
        $aiModel = $item->ai_model_used ? AIModel::tryFrom($item->ai_model_used) : AIModel::GeminiFlash;
        if (!$aiModel) $aiModel = AIModel::GeminiFlash;

        $prompt = $this->buildPostPrompt($brandContext, $item);

        try {
            // Gerar legenda
            $captionResponse = $this->aiGateway->chat(
                model: $aiModel,
                messages: [
                    ['role' => 'system', 'content' => $prompt],
                    ['role' => 'user', 'content' => "Crie o post completo para a pauta: \"{$item->title}\"\n\nDescricao: " . ($item->description ?? 'Sem descricao adicional')],
                ],
                feature: 'calendar_post_gen',
                options: ['temperature' => 0.85, 'max_tokens' => 2000],
            );

            // Gerar hashtags
            $hashtagPrompt = SocialPrompts::hashtagSystemPrompt(
                \App\Enums\SocialPlatform::tryFrom($platform) ?? \App\Enums\SocialPlatform::Instagram
            );

            $hashtagResponse = $this->aiGateway->chat(
                model: AIModel::GeminiFlash,
                messages: [
                    ['role' => 'system', 'content' => $hashtagPrompt],
                    ['role' => 'user', 'content' => "Gere hashtags para esta legenda sobre \"{$item->title}\":\n\n{$captionResponse['content']}"],
                ],
                feature: 'calendar_hashtags',
                options: ['temperature' => 0.6, 'max_tokens' => 500],
            );

            $hashtags = $this->parseHashtags($hashtagResponse['content']);
            $totalTokens = ($captionResponse['input_tokens'] ?? 0) + ($captionResponse['output_tokens'] ?? 0)
                + ($hashtagResponse['input_tokens'] ?? 0) + ($hashtagResponse['output_tokens'] ?? 0);

            $suggestion = ContentSuggestion::create([
                'brand_id' => $brand->id,
                'content_rule_id' => null,
                'title' => $item->title,
                'caption' => trim($captionResponse['content']),
                'hashtags' => $hashtags,
                'platforms' => $item->platforms ?? [$platform],
                'post_type' => $item->post_type ?? 'feed',
                'status' => 'pending',
                'ai_model_used' => $aiModel->value,
                'tokens_used' => $totalTokens,
                'metadata' => [
                    'calendar_item_id' => $item->id,
                    'calendar_date' => $item->scheduled_date->format('Y-m-d'),
                    'category' => $item->category,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]);

            // Atualizar item do calendário
            $item->update([
                'status' => 'generated',
                'suggestion_id' => $suggestion->id,
            ]);

            SystemLog::info('content', 'calendar.post_generated', "Post gerado da pauta \"{$item->title}\" para marca #{$brand->id}", [
                'calendar_item_id' => $item->id,
                'suggestion_id' => $suggestion->id,
                'tokens' => $totalTokens,
            ]);

            return $suggestion;

        } catch (\Throwable $e) {
            SystemLog::error('content', 'calendar.post_gen_error', "Erro ao gerar post da pauta #{$item->id}: {$e->getMessage()}", [
                'calendar_item_id' => $item->id,
                'brand_id' => $brand->id,
            ]);
            return null;
        }
    }

    /**
     * Gera posts para todos os itens pendentes de um período.
     */
    public function generatePostsForPendingItems(int $brandId, ?string $startDate = null, ?string $endDate = null, int $limit = 10): array
    {
        $query = ContentCalendarItem::where('brand_id', $brandId)
            ->where('status', 'pending')
            ->where(fn($q) => $q->whereNull('batch_status')->orWhere('batch_status', 'approved'))
            ->where('scheduled_date', '>=', now()->subDay()->toDateString());

        if ($startDate) $query->where('scheduled_date', '>=', $startDate);
        if ($endDate) $query->where('scheduled_date', '<=', $endDate);

        $items = $query->orderBy('scheduled_date')->limit($limit)->get();

        $generated = 0;
        $errors = 0;

        foreach ($items as $item) {
            $result = $this->generatePostFromItem($item);
            if ($result) {
                $generated++;
            } else {
                $errors++;
            }
        }

        return ['generated' => $generated, 'errors' => $errors, 'total' => $items->count()];
    }

    // ===== PRIVATE METHODS =====

    private function buildCalendarPrompt(
        string $brandContext, string $startDate, string $endDate,
        int $totalPosts, string $platforms, string $categories,
        string $tone, string $recentPosts, array $existingDates,
        string $extraInstructions, string $intelligenceReport = '',
        string $formatInstruction = ''
    ): string {
        $existingStr = !empty($existingDates)
            ? "DATAS JA OCUPADAS (nao agende nestas): " . implode(', ', $existingDates)
            : "Nenhuma data ocupada no periodo.";

        $intelligenceSection = '';
        if (!empty($intelligenceReport)) {
            $intelligenceSection = <<<INTEL

{$intelligenceReport}

## Como usar os dados de performance acima:
- Priorize os TIPOS de conteudo com melhor engajamento (ex: se Reels performam melhor, inclua mais Reels)
- Agende mais posts nos DIAS DA SEMANA com melhor engajamento
- Use os TERMOS DE BUSCA organica populares como inspiracao para temas de conteudo
- Se houver PRODUTOS mais vendidos, crie conteudo que os destaque naturalmente
- Considere a DEMOGRAFIA do publico (idade, genero, cidades) ao definir tom e temas
- Priorize as CATEGORIAS que historicamente sao mais aprovadas pelo usuario
- Evite categorias que costumam ser rejeitadas (a menos que sejam estrategicamente importantes)
- Se a tendencia de receita esta CAINDO, inclua mais conteudo de venda/promocao
- Se o trafego esta CRESCENDO, mantenha a estrategia e amplifique o que funciona
INTEL;
        }

        return <<<PROMPT
Voce e um estrategista de conteudo digital especialista em planejamento editorial.
Sua tarefa e criar um calendario de conteudo completo e estrategico, baseado em dados reais de performance.

{$brandContext}
{$intelligenceSection}

## Periodo: {$startDate} a {$endDate}
## Total de posts a gerar: {$totalPosts}
## Plataformas: {$platforms}
## Categorias disponiveis: {$categories}
## Tom de voz: {$tone}

## Posts recentes (evite repeticao):
{$recentPosts}

## {$existingStr}

## Instrucoes adicionais:
{$extraInstructions}

## Formato de conteudo:
{$formatInstruction}

## Regras do calendario:
- Distribua os posts de forma equilibrada ao longo do periodo (nao acumule tudo em poucos dias)
- Varie as categorias para manter o feed diversificado
- Considere datas comemorativas e sazonalidade
- Cada item deve ter um titulo claro e descricao da pauta
- Priorize dias uteis, mas inclua finais de semana quando fizer sentido
- Nao agende mais de 2 posts no mesmo dia
- Use os dados de performance fornecidos para tomar decisoes mais inteligentes

## Formato de resposta (JSON):
Responda APENAS com um array JSON valido, sem markdown, sem explicacoes:
[
    {
        "date": "YYYY-MM-DD",
        "title": "Titulo curto e descritivo da pauta",
        "description": "Descricao detalhada do que o post deve abordar, angulo, referencias",
        "category": "categoria",
        "platforms": ["instagram"],
        "post_type": "feed",
        "tone": "tom especifico para este post",
        "instructions": "instrucoes extras para a IA ao gerar o post"
    }
]
PROMPT;
    }

    private function buildPostPrompt(string $brandContext, ContentCalendarItem $item): string
    {
        $platform = $item->platforms[0] ?? 'instagram';
        $platformGuide = SocialPrompts::platformGuide(
            \App\Enums\SocialPlatform::tryFrom($platform) ?? \App\Enums\SocialPlatform::Instagram
        );

        $tone = $item->tone ?? 'o tom de voz padrao da marca';
        $instructions = $item->instructions ?? '';
        $category = $item->categoryLabel();

        return <<<PROMPT
Voce e um especialista em marketing digital e criacao de conteudo.
Sua tarefa e criar um post completo e pronto para publicacao a partir de uma pauta do calendario editorial.

{$brandContext}

## Pauta do Calendario:
- Titulo: {$item->title}
- Categoria: {$category}
- Tipo de post: {$item->post_type}
- Plataforma principal: {$platform}
- Tom de voz: {$tone}
- Data planejada: {$item->scheduled_date->format('d/m/Y')}

## Diretrizes da plataforma:
{$platformGuide}

## Instrucoes especificas:
{$instructions}

## Regras:
- Crie uma legenda COMPLETA, pronta para publicar
- Use emojis de forma natural e moderada
- Inclua call-to-action (CTA) quando apropriado
- NAO inclua hashtags (serao geradas separadamente)
- Escreva em portugues do Brasil
- Seja criativo, autentico e evite cliches
- Adapte o tamanho e estilo ao tipo de post ({$item->post_type})
- Responda APENAS com a legenda, sem explicacoes
PROMPT;
    }

    private function parseCalendarResponse(string $content): array
    {
        $cleaned = preg_replace('/```json\s*/i', '', $content);
        $cleaned = preg_replace('/```\s*/', '', $cleaned);
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);

        if (is_array($parsed) && !empty($parsed)) {
            return $parsed;
        }

        // Tentar extrair JSON de dentro do texto
        if (preg_match('/\[[\s\S]*\]/', $cleaned, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        return [];
    }

    private function parseHashtags(string $text): array
    {
        preg_match_all('/#(\w+)/u', $text, $matches);

        if (empty($matches[1])) {
            $words = preg_split('/[\s,]+/', trim($text));
            return array_values(array_filter(array_map(function ($word) {
                $word = ltrim($word, '#');
                return $word ? '#' . $word : null;
            }, $words)));
        }

        return array_map(fn($tag) => '#' . $tag, $matches[1]);
    }
}

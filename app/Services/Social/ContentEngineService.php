<?php

namespace App\Services\Social;

use App\Enums\AIModel;
use App\Enums\SocialPlatform;
use App\Models\Brand;
use App\Models\ContentRule;
use App\Models\ContentSuggestion;
use App\Services\AI\AIGateway;
use Illuminate\Support\Facades\Log;

/**
 * Motor de geracao automatica de conteudo.
 * Gera posts baseados em pautas configuradas e sugestoes inteligentes.
 */
class ContentEngineService
{
    public function __construct(
        private readonly AIGateway $aiGateway,
    ) {}

    /**
     * Gera conteudo baseado em uma pauta configurada.
     * Cria um ContentSuggestion com status pending.
     */
    public function generateFromRule(ContentRule $rule): ?ContentSuggestion
    {
        $brand = $rule->brand;

        if (!$brand) {
            Log::warning("ContentEngine: Pauta #{$rule->id} sem marca associada");
            return null;
        }

        $brandContext = $brand->getAIContext();
        $platform = $this->pickPlatform($rule->platforms);

        $systemPrompt = SocialPrompts::contentRulePrompt($brandContext, $rule);
        $userMessage = $this->buildRuleUserMessage($rule, $brand);

        try {
            $model = AIModel::GPT4oMini;

            $captionResponse = $this->aiGateway->chat(
                model: $model,
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                feature: 'content_engine_rule',
                options: ['temperature' => 0.85, 'max_tokens' => 2000],
            );

            // Gerar hashtags separadamente
            $hashtagPrompt = SocialPrompts::hashtagSystemPrompt(
                SocialPlatform::from($platform)
            );

            $hashtagResponse = $this->aiGateway->chat(
                model: AIModel::GPT4oMini,
                messages: [
                    ['role' => 'system', 'content' => $hashtagPrompt],
                    ['role' => 'user', 'content' => "Gere hashtags para esta legenda sobre \"{$rule->name}\":\n\n{$captionResponse['content']}"],
                ],
                feature: 'content_engine_hashtags',
                options: ['temperature' => 0.6, 'max_tokens' => 500],
            );

            $hashtags = $this->parseHashtags($hashtagResponse['content']);

            $totalTokens = ($captionResponse['input_tokens'] ?? 0) + ($captionResponse['output_tokens'] ?? 0)
                + ($hashtagResponse['input_tokens'] ?? 0) + ($hashtagResponse['output_tokens'] ?? 0);

            $suggestion = ContentSuggestion::create([
                'brand_id' => $brand->id,
                'content_rule_id' => $rule->id,
                'title' => $rule->name . ' — ' . now()->format('d/m'),
                'caption' => trim($captionResponse['content']),
                'hashtags' => $hashtags,
                'platforms' => $rule->platforms,
                'post_type' => $rule->post_type,
                'status' => 'pending',
                'ai_model_used' => $model->value,
                'tokens_used' => $totalTokens,
                'metadata' => [
                    'rule_category' => $rule->category,
                    'generated_at' => now()->toISOString(),
                ],
            ]);

            Log::info("ContentEngine: Sugestão gerada da pauta #{$rule->id} para marca #{$brand->id}", [
                'suggestion_id' => $suggestion->id,
                'tokens' => $totalTokens,
            ]);

            return $suggestion;

        } catch (\Exception $e) {
            Log::error("ContentEngine: Erro ao gerar da pauta #{$rule->id}", [
                'error' => $e->getMessage(),
                'brand_id' => $brand->id,
            ]);
            return null;
        }
    }

    /**
     * Gera sugestoes inteligentes automaticas para uma marca.
     * Analisa o contexto da marca e posts anteriores para variar o conteudo.
     */
    public function generateSmartSuggestions(Brand $brand, int $count = 3): array
    {
        $brandContext = $brand->getAIContext();
        $recentPosts = $this->getRecentPostsSummary($brand);

        $systemPrompt = SocialPrompts::smartSuggestionPrompt($brandContext, $recentPosts, $count);
        $userMessage = "Gere {$count} sugestões de posts variados para a marca {$brand->name}. "
            . "Considere o segmento ({$brand->segment}), público-alvo e tom de voz. "
            . "Cada sugestão deve ter um ângulo/abordagem diferente.";

        $suggestions = [];

        try {
            $model = AIModel::GPT4oMini;

            $response = $this->aiGateway->chat(
                model: $model,
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                feature: 'content_engine_smart',
                options: ['temperature' => 0.9, 'max_tokens' => 4000],
            );

            $totalTokens = ($response['input_tokens'] ?? 0) + ($response['output_tokens'] ?? 0);
            $parsed = $this->parseSmartSuggestions($response['content']);

            foreach ($parsed as $item) {
                $suggestion = ContentSuggestion::create([
                    'brand_id' => $brand->id,
                    'content_rule_id' => null, // Gerada automaticamente
                    'title' => $item['title'] ?? 'Sugestão da IA',
                    'caption' => $item['caption'] ?? '',
                    'hashtags' => $item['hashtags'] ?? [],
                    'platforms' => $item['platforms'] ?? ['instagram'],
                    'post_type' => $item['post_type'] ?? 'feed',
                    'status' => 'pending',
                    'ai_model_used' => $model->value,
                    'tokens_used' => intval($totalTokens / max(count($parsed), 1)),
                    'metadata' => [
                        'type' => 'smart_suggestion',
                        'category' => $item['category'] ?? 'geral',
                        'generated_at' => now()->toISOString(),
                    ],
                ]);

                $suggestions[] = $suggestion;
            }

            Log::info("ContentEngine: {$count} sugestões inteligentes geradas para marca #{$brand->id}", [
                'total_tokens' => $totalTokens,
                'count' => count($suggestions),
            ]);

        } catch (\Exception $e) {
            Log::error("ContentEngine: Erro ao gerar sugestões inteligentes", [
                'brand_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $suggestions;
    }

    // ===== PRIVATE =====

    private function pickPlatform(array $platforms): string
    {
        return $platforms[array_rand($platforms)] ?? 'instagram';
    }

    private function buildRuleUserMessage(ContentRule $rule, Brand $brand): string
    {
        $message = "Crie um post para as plataformas: " . implode(', ', $rule->platforms) . ".\n";
        $message .= "Categoria: {$rule->categoryLabel()}\n";
        $message .= "Tipo de post: {$rule->post_type}\n";

        if ($rule->tone_override) {
            $message .= "Tom de voz: {$rule->tone_override}\n";
        }

        if ($rule->description) {
            $message .= "Descrição da pauta: {$rule->description}\n";
        }

        if ($rule->instructions) {
            $message .= "Instruções especiais: {$rule->instructions}\n";
        }

        $message .= "\nCrie uma legenda completa, pronta para publicação, seguindo as diretrizes da marca.";

        return $message;
    }

    private function getRecentPostsSummary(Brand $brand): string
    {
        $recentPosts = $brand->posts()
            ->latest()
            ->limit(10)
            ->pluck('caption')
            ->filter()
            ->map(fn($caption) => mb_substr($caption, 0, 80))
            ->toArray();

        if (empty($recentPosts)) {
            return 'Nenhum post anterior encontrado.';
        }

        return "Últimos posts (resumo):\n" . implode("\n", array_map(
            fn($p, $i) => ($i + 1) . ". {$p}...",
            $recentPosts,
            array_keys($recentPosts)
        ));
    }

    private function parseSmartSuggestions(string $content): array
    {
        // Tentar parsear como JSON
        $cleaned = $content;

        // Remover markdown code blocks se presentes
        $cleaned = preg_replace('/```json\s*/i', '', $cleaned);
        $cleaned = preg_replace('/```\s*/', '', $cleaned);
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);

        if (is_array($parsed) && !empty($parsed)) {
            return $parsed;
        }

        // Fallback: retornar como uma unica sugestao
        return [[
            'title' => 'Sugestão da IA',
            'caption' => $content,
            'hashtags' => [],
            'platforms' => ['instagram'],
            'post_type' => 'feed',
            'category' => 'geral',
        ]];
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

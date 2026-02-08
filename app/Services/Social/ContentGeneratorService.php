<?php

namespace App\Services\Social;

use App\Enums\AIModel;
use App\Enums\PostType;
use App\Enums\SocialPlatform;
use App\Models\Brand;
use App\Models\User;
use App\Services\AI\AIGateway;

/**
 * Servico para geracao de conteudo social com IA.
 * Utiliza o AIGateway e prompts otimizados por plataforma.
 */
class ContentGeneratorService
{
    public function __construct(
        private readonly AIGateway $aiGateway,
    ) {}

    /**
     * Gera legenda + hashtags para um post social
     *
     * @return array{caption: string, hashtags: string[], model: string, tokens: array}
     */
    public function generateCaption(
        ?Brand $brand,
        ?User $user,
        SocialPlatform $platform,
        PostType $postType,
        string $topic,
        ?string $tone = null,
        ?string $instructions = null,
        AIModel $model = AIModel::GPT4oMini,
    ): array {
        $brandContext = $brand?->getAIContext();

        // Gerar legenda
        $systemPrompt = SocialPrompts::captionSystemPrompt($platform, $brandContext);

        $userMessage = "Crie uma legenda para {$platform->label()} sobre: {$topic}";
        $userMessage .= "\nTipo de post: {$postType->label()}";

        if ($tone) {
            $userMessage .= "\nTom de voz desejado: {$tone}";
        }

        if ($instructions) {
            $userMessage .= "\nInstruções adicionais: {$instructions}";
        }

        $captionResponse = $this->aiGateway->chat(
            model: $model,
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            user: $user,
            feature: 'post_caption',
            options: ['temperature' => 0.8, 'max_tokens' => 2000],
        );

        // Gerar hashtags
        $hashtagSystemPrompt = SocialPrompts::hashtagSystemPrompt($platform);

        $hashtagResponse = $this->aiGateway->chat(
            model: AIModel::GPT4oMini, // Usar modelo mais rapido para hashtags
            messages: [
                ['role' => 'system', 'content' => $hashtagSystemPrompt],
                ['role' => 'user', 'content' => "Gere hashtags para esta legenda sobre \"{$topic}\" na plataforma {$platform->label()}:\n\n{$captionResponse['content']}"],
            ],
            user: $user,
            feature: 'post_hashtags',
            options: ['temperature' => 0.6, 'max_tokens' => 500],
        );

        // Processar hashtags
        $hashtags = $this->parseHashtags($hashtagResponse['content']);

        return [
            'caption' => trim($captionResponse['content']),
            'hashtags' => $hashtags,
            'model' => $model->value,
            'tokens' => [
                'caption_input' => $captionResponse['input_tokens'],
                'caption_output' => $captionResponse['output_tokens'],
                'hashtag_input' => $hashtagResponse['input_tokens'],
                'hashtag_output' => $hashtagResponse['output_tokens'],
            ],
        ];
    }

    /**
     * Gera apenas hashtags para uma legenda existente
     *
     * @return array{hashtags: string[], tokens: array}
     */
    public function generateHashtags(
        ?Brand $brand,
        ?User $user,
        SocialPlatform $platform,
        string $caption,
        AIModel $model = AIModel::GPT4oMini,
    ): array {
        $systemPrompt = SocialPrompts::hashtagSystemPrompt($platform);

        $response = $this->aiGateway->chat(
            model: $model,
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Gere hashtags para esta legenda no {$platform->label()}:\n\n{$caption}"],
            ],
            user: $user,
            feature: 'post_hashtags',
            options: ['temperature' => 0.6, 'max_tokens' => 500],
        );

        return [
            'hashtags' => $this->parseHashtags($response['content']),
            'tokens' => [
                'input' => $response['input_tokens'],
                'output' => $response['output_tokens'],
            ],
        ];
    }

    /**
     * Gera variacoes de uma legenda existente
     *
     * @return array{variations: array, tokens: array}
     */
    public function generateVariations(
        ?User $user,
        string $caption,
        int $count = 3,
        AIModel $model = AIModel::GPT4oMini,
    ): array {
        $systemPrompt = SocialPrompts::variationPrompt($count);

        $response = $this->aiGateway->chat(
            model: $model,
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $caption],
            ],
            user: $user,
            feature: 'post_variations',
            options: ['temperature' => 0.9, 'max_tokens' => 3000],
        );

        $variations = [];
        try {
            $variations = json_decode($response['content'], true) ?? [];
        } catch (\Exception $e) {
            // Se nao conseguir parsear JSON, retorna o texto bruto
            $variations = [['variation' => $response['content'], 'tone' => 'variado']];
        }

        return [
            'variations' => $variations,
            'tokens' => [
                'input' => $response['input_tokens'],
                'output' => $response['output_tokens'],
            ],
        ];
    }

    /**
     * Sugere melhores horarios de postagem
     *
     * @return array{times: array, tokens: array}
     */
    public function suggestBestTimes(
        ?User $user,
        SocialPlatform $platform,
        AIModel $model = AIModel::GPT4oMini,
    ): array {
        $systemPrompt = SocialPrompts::bestTimesPrompt($platform);

        $response = $this->aiGateway->chat(
            model: $model,
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Sugira os melhores horários para postar no {$platform->label()} no Brasil."],
            ],
            user: $user,
            feature: 'post_best_times',
            options: ['temperature' => 0.5, 'max_tokens' => 1000],
        );

        $times = [];
        try {
            $times = json_decode($response['content'], true) ?? [];
        } catch (\Exception $e) {
            $times = [];
        }

        return [
            'times' => $times,
            'tokens' => [
                'input' => $response['input_tokens'],
                'output' => $response['output_tokens'],
            ],
        ];
    }

    // ===== PRIVATE =====

    /**
     * Extrai hashtags de um texto
     */
    private function parseHashtags(string $text): array
    {
        preg_match_all('/#(\w+)/u', $text, $matches);

        if (empty($matches[1])) {
            // Tentar extrair palavras mesmo sem #
            $words = preg_split('/[\s,]+/', trim($text));
            return array_filter(array_map(function ($word) {
                $word = ltrim($word, '#');
                return $word ? '#' . $word : null;
            }, $words));
        }

        return array_map(fn($tag) => '#' . $tag, $matches[1]);
    }
}

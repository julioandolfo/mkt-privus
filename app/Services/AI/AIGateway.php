<?php

namespace App\Services\AI;

use App\Enums\AIModel;
use App\Enums\AIProvider;
use App\Models\AiUsageLog;
use App\Models\Brand;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Gateway centralizado para comunicação com modelos de IA.
 * Abstrai o provedor específico e oferece interface uniforme.
 */
class AIGateway
{
    /**
     * Envia mensagem para o modelo de IA selecionado
     *
     * @param AIModel $model Modelo a ser utilizado
     * @param array $messages Array de mensagens [{role, content}]
     * @param Brand|null $brand Marca para contexto (opcional)
     * @param User|null $user Usuário para log (opcional)
     * @param string $feature Feature que está usando (chat, post_generation, etc.)
     * @param array $options Opções adicionais (temperature, max_tokens, etc.)
     * @return array{content: string, input_tokens: int, output_tokens: int, model: string}
     */
    public function chat(
        AIModel $model,
        array $messages,
        ?Brand $brand = null,
        ?User $user = null,
        string $feature = 'chat',
        array $options = [],
    ): array {
        // Injetar contexto da marca no system prompt
        if ($brand) {
            $brandContext = $brand->getAIContext();
            array_unshift($messages, [
                'role' => 'system',
                'content' => $brandContext,
            ]);
        }

        $provider = $model->provider();

        try {
            $response = match ($provider) {
                AIProvider::OpenAI => $this->callOpenAI($model, $messages, $options),
                AIProvider::Anthropic => $this->callAnthropic($model, $messages, $options),
                AIProvider::Google => $this->callGemini($model, $messages, $options),
            };

            // Registrar log de uso
            if ($user) {
                $this->logUsage($user, $brand, $model, $feature, $response);
            }

            return $response;
        } catch (\Exception $e) {
            Log::error("AI Gateway Error [{$provider->value}]: {$e->getMessage()}", [
                'model' => $model->value,
                'feature' => $feature,
                'user_id' => $user?->id,
                'brand_id' => $brand?->id,
            ]);
            throw $e;
        }
    }

    /**
     * Gera imagem com IA (DALL-E 3 via OpenAI)
     *
     * @param string $prompt Descrição da imagem a gerar
     * @param Brand|null $brand Marca para contexto visual
     * @param User|null $user Usuário para log
     * @param string $size Tamanho: '1024x1024', '1792x1024', '1024x1792'
     * @param string $quality 'standard' ou 'hd'
     * @return array{url: string, revised_prompt: string, size: string, model: string}
     */
    public function generateImage(
        string $prompt,
        ?Brand $brand = null,
        ?User $user = null,
        string $size = '1024x1024',
        string $quality = 'standard',
    ): array {
        $apiKey = $this->resolveApiKey(AIProvider::OpenAI);

        if (!$apiKey) {
            throw new \RuntimeException('OPENAI_API_KEY não configurada. Necessária para gerar imagens com DALL-E 3.');
        }

        // Enriquecer prompt com contexto visual da marca
        $enhancedPrompt = $prompt;
        if ($brand) {
            $brandHints = "Style context: Brand '{$brand->name}'";
            if ($brand->primary_color) $brandHints .= ", primary color {$brand->primary_color}";
            if ($brand->secondary_color) $brandHints .= ", secondary color {$brand->secondary_color}";
            if ($brand->segment) $brandHints .= ", segment: {$brand->segment}";
            $enhancedPrompt = "{$brandHints}. {$prompt}";
        }

        // Limitar a 4000 chars (limite DALL-E 3)
        $enhancedPrompt = mb_substr($enhancedPrompt, 0, 4000);

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(120)->post('https://api.openai.com/v1/images/generations', [
            'model' => 'dall-e-3',
            'prompt' => $enhancedPrompt,
            'n' => 1,
            'size' => $size,
            'quality' => $quality,
            'response_format' => 'url',
        ]);

        if (!$response->successful()) {
            $errorBody = $response->json();
            $errorMsg = $errorBody['error']['message'] ?? $response->body();
            throw new \RuntimeException("DALL-E 3 Error: {$errorMsg}");
        }

        $data = $response->json();
        $imageData = $data['data'][0] ?? [];

        // Log de uso
        if ($user) {
            try {
                AiUsageLog::create([
                    'user_id' => $user->id,
                    'brand_id' => $brand?->id,
                    'provider' => 'openai',
                    'model' => 'dall-e-3',
                    'feature' => 'image_generation',
                    'input_tokens' => mb_strlen($enhancedPrompt),
                    'output_tokens' => 0,
                    'estimated_cost' => $quality === 'hd' ? 0.080 : 0.040, // Custo por imagem DALL-E 3
                ]);
            } catch (\Exception $e) {
                Log::warning("Falha ao registrar log de geração de imagem: {$e->getMessage()}");
            }
        }

        return [
            'url' => $imageData['url'] ?? '',
            'revised_prompt' => $imageData['revised_prompt'] ?? $enhancedPrompt,
            'size' => $size,
            'model' => 'dall-e-3',
        ];
    }

    /**
     * Constrói prompt de imagem para conteúdo social a partir de contexto da marca.
     * Helper reutilizável por PostController, ContentCalendarService, ContentEngineService.
     */
    public static function buildSocialImagePrompt(
        Brand $brand,
        string $topic,
        string $caption,
        string $platform = 'instagram',
        string $postType = 'feed',
        ?string $imageStyle = null,
    ): string {
        $aspectRatio = match ($postType) {
            'story', 'reel' => 'portrait (9:16)',
            'video' => 'landscape (16:9)',
            default => 'square (1:1)',
        };

        $prompt = "Create a professional social media post image for {$platform}. ";
        $prompt .= "Format: {$aspectRatio}. ";
        $prompt .= "Topic: {$topic}. ";

        if ($imageStyle) {
            $prompt .= "Visual style: {$imageStyle}. ";
        } else {
            $prompt .= "Style: modern, clean, professional, high quality. ";
        }

        if ($brand->segment) {
            $prompt .= "Industry: {$brand->segment}. ";
        }

        if ($brand->primary_color) {
            $prompt .= "Use brand colors: primary {$brand->primary_color}";
            if ($brand->secondary_color) $prompt .= ", secondary {$brand->secondary_color}";
            if ($brand->accent_color) $prompt .= ", accent {$brand->accent_color}";
            $prompt .= ". ";
        }

        $captionEssence = mb_substr(strip_tags($caption), 0, 150);
        if ($captionEssence) {
            $prompt .= "Content context: \"{$captionEssence}\". ";
        }

        $prompt .= "Do NOT include any text, words or letters in the image. ";
        $prompt .= "The image should be purely visual/photographic/illustrative. ";
        $prompt .= "Make it eye-catching and suitable for a social media feed.";

        return $prompt;
    }

    /**
     * Tenta gerar uma imagem para um ContentSuggestion, salvando no storage e no metadata.
     * Retorna o caminho relativo da imagem ou null em caso de falha.
     */
    public function tryGenerateImageForContent(
        Brand $brand,
        string $topic,
        string $caption,
        string $platform = 'instagram',
        string $postType = 'feed',
    ): ?array {
        try {
            $size = match ($postType) {
                'story', 'reel' => '1024x1792',
                'video' => '1792x1024',
                default => '1024x1024',
            };

            $prompt = self::buildSocialImagePrompt($brand, $topic, $caption, $platform, $postType);

            $result = $this->generateImage(
                prompt: $prompt,
                brand: $brand,
                size: $size,
                quality: 'standard',
            );

            if (!empty($result['url'])) {
                // Baixar e salvar localmente
                $imageContent = @file_get_contents($result['url']);
                if ($imageContent) {
                    $filename = 'ai-generated/' . uniqid('img_') . '.png';
                    \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $imageContent);

                    return [
                        'path' => $filename,
                        'url' => \Illuminate\Support\Facades\Storage::disk('public')->url($filename),
                        'prompt' => $result['revised_prompt'] ?? $prompt,
                        'size' => $size,
                        'model' => 'dall-e-3',
                    ];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::warning("Image generation for content failed: {$e->getMessage()}", [
                'brand_id' => $brand->id,
                'topic' => $topic,
            ]);
            return null;
        }
    }

    // ===== PROVIDERS =====

    /**
     * Resolve a API key para um provedor, priorizando o banco de dados sobre o .env.
     */
    private function resolveApiKey(AIProvider $provider): ?string
    {
        $dbKeyName = match ($provider) {
            AIProvider::OpenAI => 'openai_api_key',
            AIProvider::Anthropic => 'anthropic_api_key',
            AIProvider::Google => 'gemini_api_key',
        };

        // Prioridade: banco de dados (criptografado) > config > .env
        $dbKey = Setting::get('api_keys', $dbKeyName);
        if ($dbKey) {
            return $dbKey;
        }

        return match ($provider) {
            AIProvider::OpenAI => config('services.openai.api_key') ?: env('OPENAI_API_KEY'),
            AIProvider::Anthropic => config('services.anthropic.api_key') ?: env('ANTHROPIC_API_KEY'),
            AIProvider::Google => config('services.gemini.api_key') ?: env('GEMINI_API_KEY'),
        };
    }

    private function callOpenAI(AIModel $model, array $messages, array $options): array
    {
        $apiKey = $this->resolveApiKey(AIProvider::OpenAI);

        if (!$apiKey) {
            throw new \RuntimeException('OPENAI_API_KEY não configurada.');
        }

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model->value,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException("OpenAI API Error: {$response->body()}");
        }

        $data = $response->json();

        return [
            'content' => $data['choices'][0]['message']['content'] ?? '',
            'input_tokens' => $data['usage']['prompt_tokens'] ?? 0,
            'output_tokens' => $data['usage']['completion_tokens'] ?? 0,
            'model' => $model->value,
        ];
    }

    private function callAnthropic(AIModel $model, array $messages, array $options): array
    {
        $apiKey = $this->resolveApiKey(AIProvider::Anthropic);

        if (!$apiKey) {
            throw new \RuntimeException('ANTHROPIC_API_KEY não configurada.');
        }

        // Extrair system message
        $system = '';
        $chatMessages = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system .= $msg['content'] . "\n";
            } else {
                $chatMessages[] = $msg;
            }
        }

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
            'model' => $model->value,
            'system' => trim($system),
            'messages' => $chatMessages,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'temperature' => $options['temperature'] ?? 0.7,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException("Anthropic API Error: {$response->body()}");
        }

        $data = $response->json();

        return [
            'content' => $data['content'][0]['text'] ?? '',
            'input_tokens' => $data['usage']['input_tokens'] ?? 0,
            'output_tokens' => $data['usage']['output_tokens'] ?? 0,
            'model' => $model->value,
        ];
    }

    private function callGemini(AIModel $model, array $messages, array $options): array
    {
        $apiKey = $this->resolveApiKey(AIProvider::Google);

        if (!$apiKey) {
            throw new \RuntimeException('GEMINI_API_KEY não configurada.');
        }

        // Converter formato de mensagens para Gemini
        $contents = [];
        $systemInstruction = '';

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemInstruction .= $msg['content'] . "\n";
            } else {
                $contents[] = [
                    'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $msg['content']]],
                ];
            }
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'maxOutputTokens' => $options['max_tokens'] ?? 4096,
            ],
        ];

        if ($systemInstruction) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => trim($systemInstruction)]],
            ];
        }

        $response = \Illuminate\Support\Facades\Http::timeout(120)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model->value}:generateContent?key={$apiKey}", $payload);

        if (!$response->successful()) {
            throw new \RuntimeException("Gemini API Error: {$response->body()}");
        }

        $data = $response->json();

        return [
            'content' => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
            'input_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
            'output_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
            'model' => $model->value,
        ];
    }

    // ===== LOGGING =====

    private function logUsage(User $user, ?Brand $brand, AIModel $model, string $feature, array $response): void
    {
        try {
            AiUsageLog::create([
                'user_id' => $user->id,
                'brand_id' => $brand?->id,
                'provider' => $model->provider()->value,
                'model' => $model->value,
                'feature' => $feature,
                'input_tokens' => $response['input_tokens'],
                'output_tokens' => $response['output_tokens'],
                'estimated_cost' => $this->estimateCost($model, $response['input_tokens'], $response['output_tokens']),
            ]);
        } catch (\Exception $e) {
            Log::warning("Falha ao registrar log de uso de IA: {$e->getMessage()}");
        }
    }

    private function estimateCost(AIModel $model, int $inputTokens, int $outputTokens): float
    {
        // Custos aproximados por 1M tokens (USD)
        $costs = match ($model) {
            AIModel::GPT4o => ['input' => 2.50, 'output' => 10.00],
            AIModel::GPT4oMini => ['input' => 0.15, 'output' => 0.60],
            AIModel::Claude35Sonnet => ['input' => 3.00, 'output' => 15.00],
            AIModel::Claude35Haiku => ['input' => 0.25, 'output' => 1.25],
            AIModel::GeminiFlash => ['input' => 0.075, 'output' => 0.30],
            AIModel::GeminiPro => ['input' => 1.25, 'output' => 5.00],
        };

        return ($inputTokens / 1_000_000 * $costs['input']) + ($outputTokens / 1_000_000 * $costs['output']);
    }
}

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

        // Montar lista de modelos para tentar: o solicitado primeiro, depois fallbacks
        $modelsToTry = $this->buildFallbackChain($model);

        $lastException = null;

        foreach ($modelsToTry as $tryModel) {
            $provider = $tryModel->provider();
            $apiKey   = $this->resolveApiKey($provider);

            if (!$apiKey) {
                continue;
            }

            try {
                $response = match ($provider) {
                    AIProvider::OpenAI => $this->callOpenAI($tryModel, $messages, $options),
                    AIProvider::Anthropic => $this->callAnthropic($tryModel, $messages, $options),
                    AIProvider::Google => $this->callGemini($tryModel, $messages, $options),
                };

                if ($tryModel !== $model) {
                    Log::info("AI Gateway: fallback de {$model->value} para {$tryModel->value} funcionou", [
                        'feature' => $feature,
                        'original_error' => $lastException?->getMessage(),
                    ]);
                }

                if ($user) {
                    $this->logUsage($user, $brand, $tryModel, $feature, $response);
                }

                return $response;
            } catch (\Exception $e) {
                $lastException = $e;

                Log::warning("AI Gateway: falha com {$tryModel->value} ({$provider->value}), tentando próximo", [
                    'model'   => $tryModel->value,
                    'feature' => $feature,
                    'error'   => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        Log::error("AI Gateway: todos os provedores falharam para feature={$feature}", [
            'requested_model' => $model->value,
            'tried'           => array_map(fn($m) => $m->value, $modelsToTry),
            'last_error'      => $lastException?->getMessage(),
            'user_id'         => $user?->id,
            'brand_id'        => $brand?->id,
        ]);

        throw $lastException ?? new \RuntimeException('Nenhum provedor de IA disponível.');
    }

    /**
     * Monta a cadeia de fallback: modelo solicitado → alternativas com key configurada.
     * @return AIModel[]
     */
    private function buildFallbackChain(AIModel $requestedModel): array
    {
        $chain = [$requestedModel];

        // Ordem de fallback por custo-benefício
        $allModels = [
            AIModel::GeminiFlash,
            AIModel::GPT4oMini,
            AIModel::Claude35Haiku,
            AIModel::GeminiPro,
            AIModel::GPT4o,
            AIModel::Claude35Sonnet,
        ];

        foreach ($allModels as $fallback) {
            if ($fallback === $requestedModel) {
                continue;
            }
            $chain[] = $fallback;
        }

        return $chain;
    }

    /**
     * Gera imagem com IA (GPT Image via OpenAI)
     *
     * @param string $prompt Descrição da imagem a gerar
     * @param Brand|null $brand Marca para contexto visual
     * @param User|null $user Usuário para log
     * @param string $size Tamanho: '1024x1024', '1536x1024', '1024x1536'
     * @param string $quality 'auto', 'high', 'medium', 'low'
     * @param array|null $referenceImages Imagens de referência [['base64' => string, 'mime' => string], ...]
     * @return array{url: string, revised_prompt: string, size: string, model: string, stored_path: ?string}
     */
    public function generateImage(
        string $prompt,
        ?Brand $brand = null,
        ?User $user = null,
        string $size = '1024x1024',
        string $quality = 'auto',
        ?array $referenceImages = null,
    ): array {
        $apiKey = $this->resolveApiKey(AIProvider::OpenAI);

        if (!$apiKey) {
            throw new \RuntimeException('OPENAI_API_KEY não configurada.');
        }

        $enhancedPrompt = $prompt;
        if ($brand) {
            $brandHints = "Brand: '{$brand->name}'";
            if ($brand->segment) $brandHints .= " ({$brand->segment})";
            if ($brand->primary_color) $brandHints .= ". Brand colors: {$brand->primary_color}";
            if ($brand->secondary_color) $brandHints .= ", {$brand->secondary_color}";
            $brandHints .= ". Generate a REALISTIC, photographic-style image — like a real product photo or professional studio shot. NOT illustration, NOT cartoon, NOT digital art.";
            if (!empty($referenceImages)) {
                $brandHints .= " CRITICAL: The reference images show REAL products from this brand. You MUST preserve these products EXACTLY as they appear — same logos, text, labels, shapes, colors. Do NOT alter, replace, or reimagine the products. Place them in the requested context/composition while keeping them pixel-accurate.";
            }
            $enhancedPrompt = "{$brandHints}\n\n{$prompt}";
        }

        $sizeMap = [
            '1792x1024' => '1536x1024',
            '1024x1792' => '1024x1536',
        ];
        $mappedSize = $sizeMap[$size] ?? $size;

        $qualityMap = [
            'standard' => 'auto',
            'hd' => 'high',
        ];
        $mappedQuality = $qualityMap[$quality] ?? $quality;

        $hasRefImages = !empty($referenceImages);
        $usedModel = 'gpt-5.4';

        Log::info("generateImage via Responses API", [
            'has_ref_images' => $hasRefImages,
            'ref_images_count' => $hasRefImages ? count($referenceImages) : 0,
            'size' => $mappedSize,
            'quality' => $mappedQuality,
            'prompt_length' => mb_strlen($enhancedPrompt),
        ]);

        $inputContent = [];

        if ($hasRefImages) {
            foreach ($referenceImages as $img) {
                $inputContent[] = [
                    'type' => 'input_image',
                    'image_url' => "data:{$img['mime']};base64,{$img['base64']}",
                ];
            }
        }

        $inputContent[] = [
            'type' => 'input_text',
            'text' => $enhancedPrompt,
        ];

        $payload = [
            'model' => 'gpt-5.4',
            'input' => [
                [
                    'role' => 'user',
                    'content' => $inputContent,
                ],
            ],
            'tools' => [
                [
                    'type' => 'image_generation',
                    'quality' => $mappedQuality,
                    'size' => $mappedSize,
                ],
            ],
            'tool_choice' => ['type' => 'image_generation'],
        ];

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(180)->post('https://api.openai.com/v1/responses', $payload);

        if (!$response->successful()) {
            $errorMsg = $response->json()['error']['message'] ?? $response->body();
            Log::warning("Responses API failed ({$response->status()}): {$errorMsg} — fallback to Images API");

            return $this->generateImageFallback($apiKey, $enhancedPrompt, $referenceImages, $mappedSize, $mappedQuality, $brand, $user);
        }

        $data = $response->json();
        $imageBase64 = null;
        $revisedPrompt = $enhancedPrompt;

        foreach ($data['output'] ?? [] as $output) {
            if (($output['type'] ?? '') === 'image_generation_call') {
                $imageBase64 = $output['result'] ?? null;
                $revisedPrompt = $output['revised_prompt'] ?? $revisedPrompt;
                break;
            }
        }

        $imageUrl = '';
        $tempPath = null;

        if ($imageBase64) {
            $imageBytes = base64_decode($imageBase64);
            $tempFilename = 'ai-generated/' . uniqid('gptimg_') . '.png';
            \Illuminate\Support\Facades\Storage::disk('public')->put($tempFilename, $imageBytes);
            $imageUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($tempFilename);
            $tempPath = $tempFilename;
        }

        if ($user) {
            try {
                $usage = $data['usage'] ?? [];
                AiUsageLog::create([
                    'user_id' => $user->id,
                    'brand_id' => $brand?->id,
                    'provider' => 'openai',
                    'model' => $usedModel,
                    'feature' => $hasRefImages ? 'image_generation_with_reference' : 'image_generation',
                    'input_tokens' => $usage['input_tokens'] ?? mb_strlen($enhancedPrompt),
                    'output_tokens' => $usage['output_tokens'] ?? 0,
                    'estimated_cost' => 0.040,
                ]);
            } catch (\Exception $e) {
                Log::warning("Falha ao registrar log de geração de imagem: {$e->getMessage()}");
            }
        }

        return [
            'url' => $imageUrl,
            'revised_prompt' => $revisedPrompt,
            'size' => $mappedSize,
            'model' => $usedModel,
            'stored_path' => $tempPath,
        ];
    }

    /**
     * Fallback: usa Images API diretamente caso Responses API falhe
     */
    private function generateImageFallback(
        string $apiKey,
        string $prompt,
        ?array $referenceImages,
        string $size,
        string $quality,
        ?Brand $brand,
        ?User $user,
    ): array {
        $hasRefImages = !empty($referenceImages);

        if ($hasRefImages) {
            $imagesPayload = [];
            foreach ($referenceImages as $img) {
                $imagesPayload[] = ['image_url' => "data:{$img['mime']};base64,{$img['base64']}"];
            }

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(180)->post('https://api.openai.com/v1/images/edits', [
                'model' => 'gpt-image-1.5',
                'images' => $imagesPayload,
                'prompt' => $prompt,
                'n' => 1,
                'size' => $size,
                'quality' => $quality,
            ]);
        } else {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(180)->post('https://api.openai.com/v1/images/generations', [
                'model' => 'gpt-image-1.5',
                'prompt' => $prompt,
                'n' => 1,
                'size' => $size,
                'quality' => $quality,
            ]);
        }

        if (!$response->successful()) {
            $errorMsg = $response->json()['error']['message'] ?? $response->body();
            throw new \RuntimeException("Erro na geração de imagem: {$errorMsg}");
        }

        $data = $response->json();
        $imageData = $data['data'][0] ?? [];

        $imageUrl = '';
        $tempPath = null;

        if (!empty($imageData['b64_json'])) {
            $imageBytes = base64_decode($imageData['b64_json']);
            $tempFilename = 'ai-generated/' . uniqid('gptimg_') . '.png';
            \Illuminate\Support\Facades\Storage::disk('public')->put($tempFilename, $imageBytes);
            $imageUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($tempFilename);
            $tempPath = $tempFilename;
        }

        if ($user) {
            try {
                AiUsageLog::create([
                    'user_id' => $user->id,
                    'brand_id' => $brand?->id,
                    'provider' => 'openai',
                    'model' => 'gpt-image-1.5',
                    'feature' => 'image_generation_fallback',
                    'input_tokens' => mb_strlen($prompt),
                    'output_tokens' => 0,
                    'estimated_cost' => 0.032,
                ]);
            } catch (\Exception $e) {
                Log::warning("Falha ao registrar log de geração de imagem: {$e->getMessage()}");
            }
        }

        return [
            'url' => $imageUrl,
            'revised_prompt' => $imageData['revised_prompt'] ?? $prompt,
            'size' => $size,
            'model' => 'gpt-image-1.5',
            'stored_path' => $tempPath,
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
            'story', 'reel' => 'portrait 9:16',
            'video' => 'landscape 16:9',
            default => 'square 1:1',
        };

        $prompt = "Create a REALISTIC, photographic-style image for a Brazilian social media post ({$platform}, {$aspectRatio}). ";
        $prompt .= "The image should look like a real high-quality professional photograph, NOT an illustration, NOT a cartoon, NOT a digital art piece. ";
        $prompt .= "Topic: {$topic}. ";

        if ($imageStyle) {
            $prompt .= "Visual style: {$imageStyle}. ";
        } else {
            $prompt .= "Style: realistic product photography, professional studio lighting, clean modern composition, warm and inviting, Brazilian marketing aesthetic. ";
        }

        if ($brand->segment) {
            $prompt .= "Industry/segment: {$brand->segment}. ";
        }

        if ($brand->primary_color) {
            $prompt .= "Brand color palette: primary {$brand->primary_color}";
            if ($brand->secondary_color) $prompt .= ", secondary {$brand->secondary_color}";
            if ($brand->accent_color) $prompt .= ", accent {$brand->accent_color}";
            $prompt .= ". Use these colors subtly in the background, props, or lighting tones — NOT as flat graphic overlays. ";
        }

        // Produtos: descrição detalhada para gerar imagens com produtos reais
        $products = $brand->products()->limit(5)->get();
        if ($products->isNotEmpty()) {
            $productDescriptions = $products->map(function ($p) {
                $desc = $p->label;
                if (!empty($p->description)) $desc .= " ({$p->description})";
                return $desc;
            })->implode('; ');
            $prompt .= "The brand sells these products: {$productDescriptions}. ";
            $prompt .= "IMPORTANT: Create a product photography composition showing these products or similar items in a real-world context (on a table, being used, in packaging, lifestyle setting). ";
        }

        $mascot = $brand->mascots()->primary()->first() ?? $brand->mascots()->first();
        if ($mascot) {
            $mascotDesc = $mascot->label;
            if (!empty($mascot->description)) $mascotDesc .= ": {$mascot->description}";
            $prompt .= "The brand has a mascot: \"{$mascotDesc}\". Include it only when it naturally fits the topic. ";
        }

        $captionEssence = mb_substr(strip_tags($caption), 0, 200);
        if ($captionEssence) {
            $prompt .= "Post caption context (in Portuguese): \"{$captionEssence}\". ";
        }

        // Regras de texto na imagem
        $prompt .= "If the topic naturally calls for text (promotions, announcements, tips), include SHORT text IN PORTUGUESE (Brazilian Portuguese) as clean overlay typography. ";
        $prompt .= "If no text is needed, keep the image purely photographic. ";
        $prompt .= "The final result must look like a real Instagram post from a professional Brazilian brand — NOT like AI-generated art, NOT like a tech illustration. ";
        $prompt .= "Think: product photos, lifestyle shots, studio photography, real textures, natural lighting.";

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

            $refContext = $this->analyzeReferenceAssets($brand);
            if ($refContext) {
                $prompt .= "\n\nBRAND VISUAL IDENTITY (MUST follow this style exactly): " . $refContext;
            }

            $brandRefImages = $this->extractBrandReferenceImages($brand);
            if (!empty($brandRefImages)) {
                $prompt .= "\n\nCRITICAL: Reference images from the brand's actual feed are provided. You MUST match the exact same visual style, color scheme, product presentation, and aesthetic. The generated image should look like it belongs in the same Instagram feed as these references. Use the same types of products, colors, layouts, and branding elements visible in the references.";
            }

            $result = $this->generateImage(
                prompt: $prompt,
                brand: $brand,
                size: $size,
                quality: 'auto',
                referenceImages: $brandRefImages ?: null,
            );

            if (!empty($result['stored_path'])) {
                return [
                    'path' => $result['stored_path'],
                    'url' => \Illuminate\Support\Facades\Storage::disk('public')->url($result['stored_path']),
                    'prompt' => $result['revised_prompt'] ?? $prompt,
                    'size' => $result['size'],
                    'model' => $result['model'],
                ];
            }

            if (!empty($result['url'])) {
                $imageContent = @file_get_contents($result['url']);
                if ($imageContent) {
                    $filename = 'ai-generated/' . uniqid('img_') . '.png';
                    \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $imageContent);
                    return [
                        'path' => $filename,
                        'url' => \Illuminate\Support\Facades\Storage::disk('public')->url($filename),
                        'prompt' => $result['revised_prompt'] ?? $prompt,
                        'size' => $result['size'],
                        'model' => $result['model'],
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

    /**
     * Analisa imagens de referência da marca via GPT-4o Vision para enriquecer prompts de geração.
     * Usa cache em memória por brand_id para evitar chamadas repetidas na mesma request.
     */
    private array $refCache = [];

    /**
     * Extrai imagens de referência dos brand assets como base64 para envio direto à API de imagem.
     * Fallback: usa imagens de posts publicados recentes se não houver brand assets.
     * @return array<array{base64: string, mime: string}>
     */
    private function extractBrandReferenceImages(Brand $brand): array
    {
        $images = [];

        $references = $brand->references()->limit(3)->get();
        foreach ($references as $ref) {
            $path = \Illuminate\Support\Facades\Storage::disk('public')->path($ref->file_path);
            if (!file_exists($path)) continue;
            $images[] = [
                'base64' => base64_encode(file_get_contents($path)),
                'mime' => $ref->mime_type ?? 'image/jpeg',
            ];
        }

        if (!empty($images)) return $images;

        return $this->extractRecentPostImages($brand);
    }

    /**
     * Busca imagens dos posts publicados mais recentes da marca para usar como referência visual.
     * @return array<array{base64: string, mime: string}>
     */
    private function extractRecentPostImages(Brand $brand): array
    {
        $recentMedia = \App\Models\PostMedia::whereHas('post', function ($q) use ($brand) {
            $q->where('brand_id', $brand->id)
              ->where('status', \App\Enums\PostStatus::Published);
        })
        ->where('type', 'image')
        ->whereNotNull('file_path')
        ->latest('id')
        ->limit(3)
        ->get();

        if ($recentMedia->isEmpty()) return [];

        $images = [];
        foreach ($recentMedia as $media) {
            $path = \Illuminate\Support\Facades\Storage::disk('public')->path($media->file_path);
            if (!file_exists($path)) continue;

            $fileSize = filesize($path);
            if ($fileSize > 5 * 1024 * 1024) continue;

            $images[] = [
                'base64' => base64_encode(file_get_contents($path)),
                'mime' => $media->mime_type ?? 'image/jpeg',
            ];
        }

        return $images;
    }

    /**
     * Analisa imagens de referência da marca (brand assets ou posts recentes) via GPT-4o Vision.
     */
    private function analyzeReferenceAssets(Brand $brand): ?string
    {
        if (isset($this->refCache[$brand->id])) {
            return $this->refCache[$brand->id];
        }

        $imageContents = $this->collectReferenceImageContents($brand);

        if (empty($imageContents)) {
            $this->refCache[$brand->id] = null;
            return null;
        }

        try {
            $result = $this->chat(
                model: AIModel::GPT4o,
                messages: [
                    ['role' => 'system', 'content' => 'You analyze brand reference images from their social media feed. Describe the visual style, color palette, composition, textures, mood, product types, and aesthetic in detail (max 200 words). Focus on recurring visual patterns, brand colors, typography style, and product presentation. Output in English.'],
                    ['role' => 'user', 'content' => array_merge(
                        [['type' => 'text', 'text' => "Analyze these brand images from their social media feed. Describe the visual identity, style patterns, and aesthetic so I can generate new images that match this brand perfectly:"]],
                        $imageContents,
                    )],
                ],
                brand: $brand,
                feature: 'brand_reference_analysis',
            );
            $desc = $result['content'] ?? null;
            $this->refCache[$brand->id] = $desc;
            return $desc;
        } catch (\Exception $e) {
            Log::warning("Brand reference analysis failed: {$e->getMessage()}");
            $this->refCache[$brand->id] = null;
            return null;
        }
    }

    /**
     * Coleta imagens de referência (brand assets + fallback para posts recentes) como conteúdo para Vision API
     */
    private function collectReferenceImageContents(Brand $brand): array
    {
        $imageContents = [];

        $references = $brand->references()->limit(3)->get();
        foreach ($references as $ref) {
            $path = \Illuminate\Support\Facades\Storage::disk('public')->path($ref->file_path);
            if (!file_exists($path)) continue;
            $base64 = base64_encode(file_get_contents($path));
            $mime = $ref->mime_type ?? 'image/jpeg';
            $imageContents[] = [
                'type' => 'image_url',
                'image_url' => ['url' => "data:{$mime};base64,{$base64}", 'detail' => 'low'],
            ];
        }

        if (!empty($imageContents)) return $imageContents;

        $recentMedia = \App\Models\PostMedia::whereHas('post', function ($q) use ($brand) {
            $q->where('brand_id', $brand->id)
              ->where('status', \App\Enums\PostStatus::Published);
        })
        ->where('type', 'image')
        ->whereNotNull('file_path')
        ->latest('id')
        ->limit(4)
        ->get();

        foreach ($recentMedia as $media) {
            $path = \Illuminate\Support\Facades\Storage::disk('public')->path($media->file_path);
            if (!file_exists($path)) continue;
            $fileSize = filesize($path);
            if ($fileSize > 5 * 1024 * 1024) continue;
            $base64 = base64_encode(file_get_contents($path));
            $mime = $media->mime_type ?? 'image/jpeg';
            $imageContents[] = [
                'type' => 'image_url',
                'image_url' => ['url' => "data:{$mime};base64,{$base64}", 'detail' => 'low'],
            ];
        }

        return $imageContents;
    }
}

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
        // Sem usuário explícito (jobs, content engine, autopilot) usa o dono da
        // marca como responsável pelo consumo, para que quota e AiUsageLog sejam
        // sempre aplicados — antes esse caminho passava sem limite nem registro.
        $user = $user ?? $this->resolveBillingUser($brand);

        // Limite mensal de tokens de IA do plano (no-op com billing desabilitado)
        if ($user) {
            app(\App\Services\Billing\UsageService::class)->assertCanUseAi($user, $brand);
        }

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

        // Falhas do modelo solicitado pelo usuário: capturamos separadamente
        // para poder reportar de forma clara no erro final (ver throw abaixo).
        $requestedModelError = null;
        $skippedNoKey = [];

        foreach ($modelsToTry as $tryModel) {
            $provider = $tryModel->provider();
            $apiKey   = $this->resolveApiKey($provider);

            if (!$apiKey) {
                $skippedNoKey[] = $tryModel->value;
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
                        'original_error' => $requestedModelError?->getMessage() ?? $lastException?->getMessage(),
                    ]);
                }

                if ($user) {
                    $this->logUsage($user, $brand, $tryModel, $feature, $response);
                }

                return $response;
            } catch (\Exception $e) {
                $lastException = $e;
                if ($tryModel === $model) {
                    $requestedModelError = $e;
                }

                Log::warning("AI Gateway: falha com {$tryModel->value} ({$provider->value}), tentando próximo", [
                    'model'   => $tryModel->value,
                    'feature' => $feature,
                    'error'   => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        Log::error("AI Gateway: todos os provedores falharam para feature={$feature}", [
            'requested_model' => $model->value,
            'tried'           => array_map(fn ($m) => $m->value, $modelsToTry),
            'skipped_no_key'  => $skippedNoKey,
            'last_error'      => $lastException?->getMessage(),
            'user_id'         => $user?->id,
            'brand_id'        => $brand?->id,
        ]);

        // Mensagem de erro com contexto: explicita o que aconteceu com o modelo
        // que o usuário escolheu vs. com os fallbacks, para evitar a impressão
        // (confusa) de que outro provedor foi usado sem motivo.
        $requestedLabel = $model->label();
        $requestedKeyMissing = in_array($model->value, $skippedNoKey, true);

        if ($requestedKeyMissing) {
            throw new \RuntimeException(
                "Não foi possível usar {$requestedLabel}: a API key deste provedor não está configurada. " .
                "Configure em Configurações > API Keys ou escolha outro modelo. " .
                ($lastException ? "Último fallback falhou: " . $lastException->getMessage() : '')
            );
        }

        if ($requestedModelError) {
            throw new \RuntimeException(
                "Falha ao usar {$requestedLabel}: " . $requestedModelError->getMessage(),
                0,
                $requestedModelError,
            );
        }

        throw $lastException ?? new \RuntimeException('Nenhum provedor de IA disponível.');
    }

    /**
     * Monta a cadeia de fallback: modelo solicitado → alternativas com key configurada.
     * @return AIModel[]
     */
    private function buildFallbackChain(AIModel $requestedModel): array
    {
        $chain = [$requestedModel];

        // Ordem de fallback: OpenAI primeiro (provedor padrão), depois Anthropic,
        // e Google por último. Mantém custo-benefício dentro de cada provedor
        // (modelo "mini/haiku/flash" antes do "pro/sonnet/grande").
        $allModels = [
            AIModel::GPT4oMini,
            AIModel::GPT4o,
            AIModel::Claude35Haiku,
            AIModel::Claude35Sonnet,
            AIModel::GeminiFlash,
            AIModel::GeminiPro,
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
        // Ver chat(): resolve o dono da marca quando não há usuário explícito.
        $user = $user ?? $this->resolveBillingUser($brand);

        // Limite mensal de tokens de IA do plano (no-op com billing desabilitado)
        if ($user) {
            app(\App\Services\Billing\UsageService::class)->assertCanUseAi($user, $brand);
        }

        $apiKey = $this->resolveApiKey(AIProvider::OpenAI);

        if (!$apiKey) {
            throw new \RuntimeException('OPENAI_API_KEY não configurada.');
        }

        $enhancedPrompt = $prompt;
        if ($brand) {
            $hasUserProductRef = !empty($referenceImages) && collect($referenceImages)->contains(fn($img) => empty($img['role']));

            // NÃO mencionar segmento nem nome da marca — isso induz o modelo a recuperar logos
            // de concorrentes famosos do mesmo setor a partir do conhecimento de treinamento.
            // Mensagem alinhada com buildSocialImagePrompt: foto real, não ad creative.
            $brandHints = "Output a REAL photograph — looks captured on a real camera by a real person. "
                . "NOT illustration, NOT cartoon, NOT digital art, NOT 3D render, NOT studio ad creative, NOT fantasy, NOT surreal. "
                . "No huge overlay text. No neon. No gradient backgrounds. No glossy product-spotlight composition.";

            if ($brand->primary_color) {
                $brandHints .= " Brand color hint (use SPARINGLY as a prop / wall / fabric, never as a flat overlay): {$brand->primary_color}";
                if ($brand->secondary_color) $brandHints .= " + {$brand->secondary_color}";
                $brandHints .= ".";
            }

            if ($hasUserProductRef) {
                // Foto real do produto enviada pelo usuário — preservar TUDO pixel-perfect.
                // Os rótulos/embalagens/logos do produto SÃO o produto da marca; nao mexer.
                $brandHints .= "\n\nUSER-PROVIDED PRODUCT PHOTO (PIXEL-PERFECT PRESERVATION):";
                $brandHints .= "\n- A real product photograph was uploaded by the user. This is the ACTUAL product as it exists in the real world.";
                $brandHints .= "\n- Preserve EVERYTHING on the product itself exactly as in the reference: labels, packaging text, barcodes, logos, brand marks, typography, colors, shapes, textures, materials, lighting on the product, reflections, shadows on the product. Pixel-perfect.";
                $brandHints .= "\n- DO NOT redraw, restyle, translate, recolor, blur, simplify or 'clean up' any text, label or graphic on the product. Reproduce them as photographed.";
                $brandHints .= "\n- You may change ONLY the surrounding scene: background, props, environment, lighting of the scene, secondary decorative elements that are clearly NOT part of the product.";
                $brandHints .= "\n- Treat the product as an immutable photographic insert — only the world around it can change.";
            } else {
                // Sem foto real do produto — politica no-logo para evitar que o modelo invente logos de concorrentes.
                $brandHints .= "\n\nNO-LOGO POLICY (MANDATORY):";
                $brandHints .= "\n- Do not render any logo, wordmark, brand name, monogram, badge, sticker, trademark or company insignia anywhere in the image.";
                $brandHints .= "\n- Every label slot, product front, bottle, box, tag, shirt, cup, screen, storefront, sign, vehicle, cap and packaging surface must be fully blank or solid-color — treat them as empty surfaces.";
                $brandHints .= "\n- If a reference image is provided only for style/color/composition guidance, extract the photographic style from it but ignore any logo it contains.";
                $brandHints .= "\n- Compose the scene purely from the topic and the color palette — never from memory of real companies.";
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
        // Orquestrador da Responses API (suporta a tool image_generation, que usa gpt-image-2 internamente).
        $orchestratorModel = 'gpt-4o';
        $usedModel = 'gpt-image-2';

        // Role breakdown das referências — chave para debugar vazamento de logo de terceiros.
        $refRoles = $hasRefImages ? collect($referenceImages)->map(fn($img) => $img['role'] ?? 'user_product')->countBy()->toArray() : [];

        Log::info("generateImage:request", [
            'brand_id' => $brand?->id,
            'brand_name' => $brand?->name,
            'orchestrator' => $orchestratorModel,
            'image_model' => $usedModel,
            'has_ref_images' => $hasRefImages,
            'ref_images_count' => $hasRefImages ? count($referenceImages) : 0,
            'ref_roles' => $refRoles,
            'size' => $mappedSize,
            'quality' => $mappedQuality,
            'prompt_length' => mb_strlen($enhancedPrompt),
            // Mantém o log em uma linha só para parseáveis (grep/jq) — substitui quebras por " | ".
            'prompt_full' => str_replace(["\r\n", "\r", "\n"], ' | ', $enhancedPrompt),
            'endpoint' => 'POST /v1/responses',
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

        $imageGenTool = [
            'type' => 'image_generation',
            'quality' => $mappedQuality,
            'size' => $mappedSize,
        ];

        // Só usar modo 'edit' se houver imagens de produto do usuário (sem role).
        // Logo da marca e referências visuais NÃO devem ativar modo edit —
        // senão o modelo tenta preservar tudo das referências, incluindo logos de terceiros.
        $hasUserProductImages = $hasRefImages && collect($referenceImages)->contains(fn($img) => empty($img['role']));
        if ($hasUserProductImages) {
            $imageGenTool['action'] = 'edit';
        }

        $payload = [
            'model' => $orchestratorModel,
            'input' => [
                [
                    'role' => 'user',
                    'content' => $inputContent,
                ],
            ],
            'tools' => [$imageGenTool],
            'tool_choice' => ['type' => 'image_generation'],
        ];

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(180)->post('https://api.openai.com/v1/responses', $payload);

        if (!$response->successful()) {
            $errorMsg = $response->json()['error']['message'] ?? $response->body();
            Log::warning("Responses API FAILED (orchestrator={$orchestratorModel}), status={$response->status()}: {$errorMsg}");

            // Se o erro é falta de verificação de organização para gpt-image-2,
            // cai direto no Images API com gpt-image-1 (que não exige verificação).
            $needsOrgVerification = stripos($errorMsg, 'organization must be verified') !== false
                || stripos($errorMsg, 'verify organization') !== false;
            $fallbackModel = $needsOrgVerification ? 'gpt-image-1' : 'gpt-image-2';

            Log::warning("Falling back to direct Images API with {$fallbackModel}" . ($needsOrgVerification ? ' (org not verified for gpt-image-2)' : ''));

            return $this->generateImageFallback($apiKey, $enhancedPrompt, $referenceImages, $mappedSize, $mappedQuality, $brand, $user, $fallbackModel);
        }

        $data = $response->json();
        $responseModel = $data['model'] ?? 'unknown';

        $imageBase64 = null;
        $revisedPrompt = $enhancedPrompt;

        foreach ($data['output'] ?? [] as $output) {
            if (($output['type'] ?? '') === 'image_generation_call') {
                $imageBase64 = $output['result'] ?? null;
                $revisedPrompt = $output['revised_prompt'] ?? $revisedPrompt;
                break;
            }
        }

        // Log do que o modelo DE FATO gerou — permite detectar logos de concorrentes no prompt reescrito.
        Log::info("generateImage:response", [
            'brand_id' => $brand?->id,
            'response_model' => $responseModel,
            'image_model' => $usedModel,
            'revised_prompt' => str_replace(["\r\n", "\r", "\n"], ' | ', (string) $revisedPrompt),
            'revised_prompt_changed' => $revisedPrompt !== $enhancedPrompt,
        ]);

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
        string $model = 'gpt-image-2',
    ): array {
        // Só usar endpoint /edits se houver imagens de produto do usuário (sem role)
        $hasUserProductImages = !empty($referenceImages) && collect($referenceImages)->contains(fn($img) => empty($img['role']));

        if ($hasUserProductImages) {
            $imagesPayload = [];
            foreach ($referenceImages as $img) {
                if (!empty($img['role'])) continue; // Pular logo — não editar
                $imagesPayload[] = ['image_url' => "data:{$img['mime']};base64,{$img['base64']}"];
            }

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(180)->post('https://api.openai.com/v1/images/edits', [
                'model' => $model,
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
                'model' => $model,
                'prompt' => $prompt,
                'n' => 1,
                'size' => $size,
                'quality' => $quality,
            ]);
        }

        if (!$response->successful()) {
            $errorMsg = $response->json()['error']['message'] ?? $response->body();

            // Se gpt-image-2 falhou por organização não verificada, retry automático com gpt-image-1.
            $needsOrgVerification = stripos($errorMsg, 'organization must be verified') !== false
                || stripos($errorMsg, 'verify organization') !== false;
            if ($model === 'gpt-image-2' && $needsOrgVerification) {
                Log::warning("gpt-image-2 rejeitado por falta de verificacao — retry automatico com gpt-image-1");
                return $this->generateImageFallback($apiKey, $prompt, $referenceImages, $size, $quality, $brand, $user, 'gpt-image-1');
            }

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
                    'model' => $model,
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
            'model' => $model,
            'stored_path' => $tempPath,
        ];
    }

    /**
     * Remove o nome da marca de um texto antes de enviar para o modelo de imagem.
     * Evita que o modelo invente logos baseados no conhecimento que tem da internet.
     */
    public static function stripBrandName(string $text, ?string $brandName): string
    {
        if (empty($brandName) || empty($text)) return $text;

        // Remover variações: "Marca", "marca", "MARCA", e palavras compostas
        $variants = [
            $brandName,
            mb_strtolower($brandName),
            mb_strtoupper($brandName),
            ucfirst(mb_strtolower($brandName)),
        ];

        // Adicionar versões sem espaços e separadas por palavra
        $words = preg_split('/\s+/', $brandName);
        if (count($words) > 1) {
            $variants[] = implode('', $words); // "MinhaMarca"
        }

        $variants = array_unique(array_filter($variants));

        // Substituir cada variante por placeholder neutro
        return trim(preg_replace('/\s+/', ' ', str_ireplace($variants, 'a marca', $text)));
    }

    /**
     * Constrói prompt de imagem para conteúdo social a partir de contexto da marca.
     *
     * Filosofia: o resultado precisa parecer uma FOTO REAL feita por uma pessoa,
     * não um ad creative de catálogo. Por isso o prompt:
     *  - Bane explicitamente os clichês do "look de IA" (studio lighting, neon
     *    grande, fundo gradiente, simetria perfeita, render/CGI etc).
     *  - Sorteia entre 6 estéticas de FOTOGRAFIA (lifestyle, ugc-phone, flatlay,
     *    hand-held, editorial, behind-scenes) — ou aceita uma escolhida via UI.
     *  - Torna texto-na-imagem OPCIONAL (~35% das vezes) e, quando aparece, é
     *    SEMPRE pequeno e sutil (post-it, sticker, etiqueta) — nunca o título
     *    gigante neon que estava dominando os posts.
     *  - Pede "linguagem de câmera real" (iPhone, 50mm, luz natural, leve grão).
     *
     * $aesthetic pode ser: 'auto' (sorteia), 'lifestyle', 'ugc_phone',
     * 'flatlay_natural', 'hand_held', 'editorial_soft', 'behind_scenes'.
     * Valores legados (do dropdown antigo) caem em 'auto'.
     */
    public static function buildSocialImagePrompt(
        Brand $brand,
        string $topic,
        string $caption,
        string $platform = 'instagram',
        string $postType = 'feed',
        ?string $aesthetic = null,
    ): string {
        $aspectRatio = match ($postType) {
            'story', 'reel' => 'portrait 9:16',
            'video' => 'landscape 16:9',
            default => 'square 1:1',
        };

        // Remove nome da marca de topic/caption — evita hallucination de logo.
        $cleanTopic = self::stripBrandName($topic, $brand->name);
        $cleanCaption = self::stripBrandName($caption, $brand->name);

        $template = self::resolveAestheticTemplate($aesthetic);

        $prompt  = "Photograph for a Brazilian {$platform} post ({$aspectRatio}). ";
        $prompt .= "It must read as a REAL PHOTO captured on a real camera by a real person — never as AI art, render, illustration, glossy catalog mockup, or polished ad creative. ";
        $prompt .= "Subject / theme: {$cleanTopic}.\n";

        $prompt .= "\nPHOTO STYLE — {$template['name']}:\n{$template['description']}\n";
        $prompt .= "Camera / light cues: {$template['camera']}\n";

        // === Banlist: o que mais quebra a sensação de foto real ===
        $prompt .= "\nABSOLUTELY AVOID (critical — the image fails if any of these appear): "
                . "studio 3-point lighting, seamless paper backdrop, glossy reflective tabletop with the product centered like an ad, "
                . "dramatic neon or glow text, huge bold typography filling the frame, gradient backgrounds, "
                . "render / CGI / 3D-illustration feel, hyper-symmetric composition, oversaturated colors, "
                . "plastic-looking skin, dreamlike or surreal atmospheres, fantasy elements, sci-fi lighting, "
                . "dark moody product-spotlight shots, stock-photo clichés (hands clapping, smiling at laptop, perfect teeth), "
                . "generic emojis baked into the image, fake watermarks, fake logos invented by the model.\n";

        // Cor da marca — sugestão MUITO suave (antes era ordem dura e saturava tudo).
        if ($brand->primary_color) {
            $prompt .= "\nBrand color hint (use SPARINGLY — show it as a piece of fabric, a wall paint, a small prop or accent, NEVER as a flat overlay or background fill): {$brand->primary_color}";
            if ($brand->secondary_color) $prompt .= " accompanied by {$brand->secondary_color}";
            $prompt .= ". The colors must feel like they belong to the real scene, not like a brand asset pasted on top.\n";
        }

        $products = $brand->products()->limit(5)->get();
        if ($products->isNotEmpty()) {
            $productDescriptions = $products->map(function ($p) {
                $desc = $p->label;
                if (!empty($p->description)) $desc .= " ({$p->description})";
                return $desc;
            })->implode('; ');
            $prompt .= "\nBrand products: {$productDescriptions}. "
                    . "Pick the ONE product most relevant to the theme. Show it INTEGRATED into the scene — being used, being held, sitting on a real surface beside everyday objects. "
                    . "Never floating, never centered like a catalog hero shot. Only ONE product per image.\n";
        }

        $mascot = $brand->mascots()->primary()->first() ?? $brand->mascots()->first();
        if ($mascot) {
            $mascotDesc = $mascot->label;
            if (!empty($mascot->description)) $mascotDesc .= ": {$mascot->description}";
            $prompt .= "\nBrand has a mascot (\"{$mascotDesc}\"). Include only if it makes natural sense for the theme.\n";
        }

        $captionEssence = mb_substr(strip_tags($cleanCaption), 0, 180);
        if ($captionEssence) {
            $prompt .= "\nCaption context (Portuguese, for vibe only — do NOT render this text in the image): \"{$captionEssence}\".\n";
        }

        // === Texto na imagem: opcional e SEMPRE pequeno/sutil ===
        // 35% das vezes inclui texto. Quando inclui, é texto físico (post-it,
        // sticker, etiqueta de papel, giz em quadro) — nunca overlay gigante.
        $includeText = mt_rand(1, 100) <= 35;
        if ($includeText) {
            $textStyles = [
                'a small handwritten note on a real paper visible in the scene, 2–5 Portuguese words, casual handwriting',
                'a discreet round paper sticker on or near the product with 1–3 Portuguese words in a simple sans-serif',
                'tiny chalk handwriting on a small chalkboard prop, 2–5 Portuguese words',
                'a folded paper tag tied to the product with 2–4 handwritten Portuguese words',
                'a small piece of masking tape on a surface with a few Portuguese words written by hand',
            ];
            $textStyle = $textStyles[array_rand($textStyles)];
            $prompt .= "\nText in image: include a SUBTLE small typographic element — {$textStyle}. "
                    . "Never large overlay text covering the frame. Never neon, glow, outlined or 3D fonts. "
                    . "The text is part of the physical scene (printed/written on a real object), not a graphic layer on top of the photo.\n";
        } else {
            $prompt .= "\nText in image: NONE. Pure photograph — no words, no overlays, no graphic captions inside the image.\n";
        }

        $prompt .= "\nFinal target: looks like a real photo a small business owner or independent photographer would post on Instagram — honest, lived-in, slightly imperfect framing. NOT polished ad creative.";

        return $prompt;
    }

    /**
     * Resolve template de estética por nome OU sorteia entre os 6 presets
     * quando 'auto' / null / valor legado. Templates definem composição,
     * ambiente e linguagem de câmera — é o que mais muda o "look" final.
     *
     * @return array{name: string, description: string, camera: string}
     */
    private static function resolveAestheticTemplate(?string $aesthetic): array
    {
        $templates = [
            'lifestyle' => [
                'name'        => 'Lifestyle in context',
                'description' => 'A real person (or a glimpse of them — hands, partial silhouette, shoulder, back of head) using the product in a real everyday environment: home kitchen counter, home office desk, sofa, café table, garden, bedroom. Surroundings should feel lived-in — a coffee cup half full, a stack of magazines, a plant slightly out of focus. Never a model posing for camera; always candid.',
                'camera'      => 'shot on iPhone 15 Pro or Fuji X100, 35mm equivalent, natural window light from the side, shallow depth of field, no flash, mild grain, color slightly muted',
            ],
            'ugc_phone' => [
                'name'        => 'UGC / phone-camera style',
                'description' => 'Looks like a customer snapped a quick casual photo of the product with their phone to share with a friend. Slightly off-center framing, a relaxed angle, candid feel. Small imperfections are welcome — a thumb shadow at the edge, a slightly tilted horizon, a touch of motion blur. Background is a real domestic surface (kitchen counter, office desk, sofa cushion, bathroom shelf). Never a perfectly symmetric studio shot.',
                'camera'      => 'phone camera quality, front-facing ceiling light or window light, handheld vibe, slightly low resolution feel, real-world color cast',
            ],
            'flatlay_natural' => [
                'name'        => 'Flat lay on a real surface',
                'description' => 'Top-down view on a textured natural surface: raw oak wood, linen cloth, terrazzo, weathered concrete, a rug, marble with veins, a wooden cutting board. The product sits naturally among everyday companion items that make sense for the theme — a hardcover notebook, a coffee cup, keys, a small plant, a printed magazine, a folded napkin. Asymmetric arrangement, items partially cropped at the frame edges.',
                'camera'      => 'top-down 90° angle, soft natural daylight from a side window, gentle directional shadows, no studio softbox, no perfectly even lighting',
            ],
            'hand_held' => [
                'name'        => 'Held in hand close-up',
                'description' => 'Close-up of a real human hand (or two) holding, opening, or interacting with the product. Realistic skin texture: pores, fine hair, small imperfections, natural skin tone variation. The background is the real world out of focus — a room interior, an outdoor café, a kitchen. Hand provides scale and intimacy; product is the focus.',
                'camera'      => '50mm portrait lens, wide aperture f/2, shallow depth of field, warm natural light, soft bokeh, slight chromatic aberration at the edges',
            ],
            'editorial_soft' => [
                'name'        => 'Editorial soft',
                'description' => 'A thoughtfully composed but unstaged scene. Single product placed deliberately in a real interior or outdoor setting. Plenty of negative space. A few real-world props: a folded textile, a single stem of flower, a half-empty ceramic cup, an open book. Magazine-still-life feel but NOT catalog.',
                'camera'      => 'mirrorless camera with 85mm lens, soft window light from the left, gentle shadows on the right, slight film grain, low contrast color grade',
            ],
            'behind_scenes' => [
                'name'        => 'Behind the scenes',
                'description' => 'Workshop / atelier / kitchen / studio scene showing the product in its making or staging context. Visible tools nearby, packaging materials, raw ingredients, work-in-progress vibe. Honest documentary feel — a slightly messy workbench, a notebook with notes, a person\'s arms partially in frame working. Lived-in workspace.',
                'camera'      => 'documentary photography style, available light only, candid angle, mild grain, natural color balance, no retouching look',
            ],
        ];

        $key = $aesthetic ?: 'auto';

        // Aliases das opções antigas do dropdown — todas caem em 'auto' (sorteio).
        $legacyValues = [
            '', 'auto',
            'flat design, minimalist, vector illustration',
            'photorealistic, professional photography',
            '3D render, modern, glossy',
            'watercolor, artistic, soft',
            'neon, vibrant, dark background',
            'vintage, retro, film grain',
            'geometric, abstract, modern',
            'hand drawn, sketch, creative',
        ];
        if (in_array($key, $legacyValues, true) || !isset($templates[$key])) {
            $key = array_rand($templates);
        }

        return $templates[$key];
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

            // Refs precisam ser extraídas ANTES do bloco de instruções pra
            // que o prompt possa diferenciar entre "foto real do produto"
            // (que deve ser ancorada visualmente) vs. "post recente / asset
            // de estilo" (que serve só pra alinhar a estética).
            $brandRefImages = $this->extractBrandReferenceImages($brand);
            $hasProductPhoto = !empty($brandRefImages) && collect($brandRefImages)->contains(fn ($i) => ($i['role'] ?? '') === 'product');

            if ($hasProductPhoto) {
                // Foto REAL do produto vai no payload — o modelo precisa saber que
                // deve ANCORAR o produto da cena nessa foto, não inventar do zero.
                $prompt .= "\n\nPRODUCT PHOTO PROVIDED: Among the reference images you receive, the one(s) tagged as the brand's product show the REAL physical product being sold. Use it as the visual anchor for the product appearing in your generated scene — keep colors, materials, shape and packaging style consistent with the photo. The surrounding scene (environment, lighting, props, composition) follows the PHOTO STYLE described earlier; only the product itself stays faithful to the reference. Do NOT replicate any logo, wordmark or brand insignia from the reference — these go through the NO-LOGO policy.";
            }

            if (!empty($brandRefImages)) {
                $prompt .= "\n\nSTYLE REFERENCES: Other reference images (recent posts of this brand, brand identity references) are provided only for visual style, color scheme, composition and aesthetic — the generated image should feel like it belongs in the same Instagram feed as these references. Do NOT copy, redraw or include any logo, wordmark or branding that may appear in them; extract only the photographic style.";
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

        // Chave no header (não na query string) — URLs vazam em logs de proxy/APM.
        $response = \Illuminate\Support\Facades\Http::timeout(120)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model->value}:generateContent", $payload);

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

    /**
     * Usuário responsável pelo consumo de IA de uma marca (para quota/telemetria)
     * quando a chamada não vem de um request autenticado. Prioriza o Owner e cai
     * para o primeiro membro; retorna null se a marca não tiver membros.
     */
    private function resolveBillingUser(?Brand $brand): ?User
    {
        if (!$brand) {
            return null;
        }

        $owner = $brand->users()
            ->wherePivot('role', \App\Enums\BrandRole::Owner->value)
            ->first();

        return $owner ?? $brand->users()->first();
    }

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
     *
     * Prioridade dos slots (cap total = 4 imagens):
     *   1) PRODUTOS cadastrados (BrandAsset category='product') — até 2.
     *      São as fotos reais do produto que o usuário subiu na tela de Marca.
     *      O modelo USA estas como base do que está sendo vendido — sem elas,
     *      o resultado é um produto genérico que não tem nada a ver com a marca.
     *   2) REFERÊNCIAS de identidade visual (category='reference') — até 2.
     *      Cores/estilo/composição que a marca quer transmitir.
     *   3) POSTS recentes publicados — preenche o restante.
     *      Alinha o resultado ao estilo do feed atual da marca.
     *
     * @return array<array{base64: string, mime: string, role: string}>
     */
    private function extractBrandReferenceImages(Brand $brand): array
    {
        $images = [];
        $cap = 4;

        // Política: NÃO enviamos o logo como referência — posts sociais modernos não aplicam logo
        // na imagem. Incluir o logo como referência induz o modelo a desenhá-lo no resultado.

        // 1) PRODUTOS da marca (FOTO REAL — máxima prioridade)
        //    Bug anterior: o pipeline automatizado (Content Engine) ignorava
        //    completamente $brand->products() e gerava produtos inventados.
        //    Agora pegamos até 2 fotos de produto cadastradas e mandamos como
        //    referência visual com role='product' (não ativa edit mode, mas
        //    o modelo passa a ter ancoragem visual concreta do que vender).
        foreach ($brand->products()->limit(2)->get() as $product) {
            if (count($images) >= $cap) break;
            $img = $this->readAssetAsBase64($product);
            if ($img) {
                $images[] = $img + ['role' => 'product'];
            }
        }

        // 2) Referências visuais cadastradas (estilo/cor/composição)
        $slotsLeft = $cap - count($images);
        if ($slotsLeft > 0) {
            foreach ($brand->references()->limit(min(2, $slotsLeft))->get() as $ref) {
                if (count($images) >= $cap) break;
                $img = $this->readAssetAsBase64($ref);
                if ($img) {
                    $images[] = $img + ['role' => 'reference'];
                }
            }
        }

        // 3) Últimos posts publicados — preenche o restante
        $slotsLeft = $cap - count($images);
        if ($slotsLeft > 0) {
            foreach ($this->extractRecentPostImages($brand, $slotsLeft) as $postImg) {
                $images[] = $postImg + ['role' => 'recent_post'];
            }
        }

        return $images;
    }

    /**
     * Lê um BrandAsset do disco e retorna {base64, mime} se válido.
     * Encapsula path resolution, size cap (5MB) e file_exists check.
     *
     * @return array{base64: string, mime: string}|null
     */
    private function readAssetAsBase64(\App\Models\BrandAsset $asset): ?array
    {
        if (!$asset->file_path) {
            return null;
        }

        $path = \Illuminate\Support\Facades\Storage::disk('public')->path($asset->file_path);
        if (!file_exists($path)) {
            return null;
        }

        $size = filesize($path);
        if ($size === false || $size > 5 * 1024 * 1024) {
            return null;
        }

        return [
            'base64' => base64_encode(file_get_contents($path)),
            'mime' => $asset->mime_type ?? 'image/jpeg',
        ];
    }

    /**
     * Busca imagens dos posts publicados mais recentes da marca para usar como referência visual.
     * @return array<array{base64: string, mime: string}>
     */
    private function extractRecentPostImages(Brand $brand, int $limit = 3): array
    {
        // Respeitar "ai_reference_since" se configurado; caso contrário usa
        // fallback de 60 dias (era null antes, o que combinado com query sem
        // limite de data podia trazer posts muito antigos não representativos
        // do estilo visual atual da marca, ou nada se a config estiver mal-set).
        $cfg = $brand->getContentEngineConfig();
        $referenceSince = $cfg['ai_reference_since'] ?? now()->subDays(60)->toDateString();

        $recentMedia = \App\Models\PostMedia::whereHas('post', function ($q) use ($brand, $referenceSince) {
            // Bug anterior: filtrava só por posts.brand_id direto.
            // Após a migração post_brand_pivot (2026_05_04), brand_id virou
            // nullable e a visibilidade real vem da pivot post_brand. Sem o
            // whereHas('brands'), posts da marca cuja FK direta foi nullificada
            // (cross-brand) sumiam, e posts órfãos (brand_id=NULL) de outras
            // marcas podiam vazar. Agora cobrimos ambos os caminhos.
            $q->where('status', \App\Enums\PostStatus::Published)
              ->where(function ($w) use ($brand) {
                  $w->where('brand_id', $brand->id)
                    ->orWhereHas('brands', fn($pb) => $pb->where('brands.id', $brand->id));
              });
            $q->where('created_at', '>=', $referenceSince);
        })
        ->where('type', 'image')
        ->whereNotNull('file_path')
        ->latest('id')
        ->limit(max(1, $limit))
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
            // IMPORTANTE: NÃO passar $brand para evitar injeção do nome da marca no system prompt.
            // O modelo de imagem usa essa descrição depois e poderia hallucinate logos baseado no nome.
            $result = $this->chat(
                model: AIModel::GPT4o,
                messages: [
                    ['role' => 'system', 'content' => 'You analyze visual reference images for image generation. Describe ONLY the visual style, color palette, composition, textures, mood, product types, and aesthetic in detail (max 200 words). Focus on recurring visual patterns, colors, typography style, and product presentation. CRITICAL: Do NOT mention any brand names, company names, logos, wordmarks, or trademark identifiers. Describe products and elements generically (e.g. "a beverage bottle" not "a Coca-Cola bottle"). Output in English.'],
                    ['role' => 'user', 'content' => array_merge(
                        [['type' => 'text', 'text' => "Analyze these reference images. Describe the visual identity, style patterns, and aesthetic generically — without naming any brand. Focus on style, colors, composition, lighting:"]],
                        $imageContents,
                    )],
                ],
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

        // 1) PRODUTOS — fotos reais cadastradas pela marca. Bug anterior: o
        //    Vision nunca via os produtos, então a descrição "BRAND VISUAL
        //    IDENTITY" gerada pra ancorar o prompt da imagem ignorava o que
        //    a marca de fato vende. Agora produto entra no Vision também.
        foreach ($brand->products()->limit(2)->get() as $product) {
            $img = $this->readAssetAsBase64($product);
            if ($img) {
                $imageContents[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => "data:{$img['mime']};base64,{$img['base64']}", 'detail' => 'low'],
                ];
            }
        }

        foreach ($brand->references()->limit(2)->get() as $ref) {
            $img = $this->readAssetAsBase64($ref);
            if ($img) {
                $imageContents[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => "data:{$img['mime']};base64,{$img['base64']}", 'detail' => 'low'],
                ];
            }
        }

        if (!empty($imageContents)) return $imageContents;

        // Fallback: nenhum produto/referência cadastrado — usa últimos posts
        // publicados como base pro Vision descrever o estilo visual da marca.
        // Default de 60 dias quando ai_reference_since não está configurado.
        $cfg = $brand->getContentEngineConfig();
        $referenceSince = $cfg['ai_reference_since'] ?? now()->subDays(60)->toDateString();

        $recentMedia = \App\Models\PostMedia::whereHas('post', function ($q) use ($brand, $referenceSince) {
            // Cobre tanto posts.brand_id direto quanto cross-brand via pivô
            // post_brand (migração 2026_05_04 tornou brand_id nullable e
            // delegou visibilidade pra pivot — sem isso, posts cross-brand
            // da marca não eram capturados ou posts órfãos de outras vazavam).
            $q->where('status', \App\Enums\PostStatus::Published)
              ->where(function ($w) use ($brand) {
                  $w->where('brand_id', $brand->id)
                    ->orWhereHas('brands', fn ($pb) => $pb->where('brands.id', $brand->id));
              })
              ->where('created_at', '>=', $referenceSince);
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

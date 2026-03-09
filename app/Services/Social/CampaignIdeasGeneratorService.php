<?php

namespace App\Services\Social;

use App\Enums\AIModel;
use App\Enums\PostType;
use App\Enums\PlatformType;
use App\Models\Brand;
use App\Services\AI\AIGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Gerador de ideias de campanhas inspirado no Pomeli
 * Cria 5-10 ideias de campanha completas baseadas no Brand DNA
 */
class CampaignIdeasGeneratorService
{
    private const CACHE_TTL = 3600; // 1 hora

    public function __construct(
        private BrandDNAAnalyzerService $brandAnalyzer,
        private AIGateway $aiGateway
    ) {}

    /**
     * Gera ideias de campanha para a marca
     *
     * @param Brand $brand Marca a gerar campanhas
     * @param string|null $theme Tema opcional (ex: "Black Friday", "Lançamento")
     * @param int $count Quantidade de ideias (3-10)
     * @return array Ideias de campanha completas
     */
    public function generateCampaignIdeas(Brand $brand, ?string $theme = null, int $count = 5): array
    {
        $cacheKey = "campaign_ideas:{$brand->id}:" . md5($theme ?? 'general') . ":{$count}";

        // Verifica cache
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            // 1. Obtém Brand DNA
            $brandDNA = $this->brandAnalyzer->analyzeBrandFromWebsite($brand);

            // 2. Gera campanhas via IA
            $campaigns = $this->generateWithAI($brand, $brandDNA, $theme, $count);

            // 3. Enriquece com metadados (incluindo dados sociais)
            $socialData = $brandDNA['social_data'] ?? [];
            $enriched = $this->enrichCampaigns($campaigns, $brand, $socialData);

            // 4. Salva no cache
            Cache::put($cacheKey, $enriched, self::CACHE_TTL);

            return $enriched;
        } catch (\Exception $e) {
            Log::error("Campaign generation failed for brand {$brand->id}: {$e->getMessage()}", [
                'brand_id' => $brand->id,
                'theme' => $theme,
                'error' => $e->getMessage(),
            ]);

            return $this->getFallbackCampaigns($brand, $theme, $count);
        }
    }

    /**
     * Gera campanha a partir de uma ideia específica do usuário
     */
    public function generateFromUserIdea(Brand $brand, string $userIdea, int $postCount = 3): array
    {
        $brandDNA = $this->brandAnalyzer->analyzeBrandFromWebsite($brand);

        $prompt = $this->buildUserIdeaPrompt($brand, $brandDNA, $userIdea, $postCount);

        $response = $this->aiGateway->chat(
            model: AIModel::GeminiPro,
            messages: [['role' => 'user', 'content' => $prompt]],
            brand: $brand,
            feature: 'campaign_from_user_idea',
            options: ['temperature' => 0.7, 'max_tokens' => 2500]
        );

        $result = json_decode($response['content'], true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($result['campaign'])) {
            throw new \RuntimeException('Failed to generate campaign from user idea');
        }

        return $this->enrichSingleCampaign($result['campaign'], $brand);
    }

    /**
     * Regenera uma campanha específica com variações
     */
    public function regenerateCampaignVariant(Brand $brand, array $originalCampaign, string $variationType = 'different_angle'): array
    {
        $brandDNA = $this->brandAnalyzer->analyzeBrandFromWebsite($brand);

        $prompt = <<<PROMPT
Você é um estrategista de marketing criativo. Crie uma VARIAÇÃO da campanha abaixo.

MARCA: {$brand->name}
Brand DNA: {$brandDNA['voice_tone']['personality']}

CAMPANHA ORIGINAL:
- Nome: {$originalCampaign['name']}
- Conceito: {$originalCampaign['concept']}
- Objetivo: {$originalCampaign['objective']}

TIPO DE VARIAÇÃO: {$variationType}
- "different_angle": Mesmo conceito, ângulo diferente
- "different_format": Mesma ideia, formato de conteúdo diferente
- "expanded": Expandir a campanha com mais posts
- "condensed": Versão resumida/essencial
- "humorous": Versão mais leve/engraçada
- "serious": Versão mais séria/formal

Crie uma variação mantendo a essência mas alterando conforme solicitado.

Retorne JSON:
{
  "name": "Nome da variação",
  "concept": "Conceito adaptado",
  "objective": "objetivo",
  "platforms": ["platforms"],
  "format": "format",
  "angle_difference": "O que mudou nesta variação",
  "posts": [
    {"angle": "ângulo", "hook": "gancho", "cta": "cta", "visual_description": "descrição visual"}
  ]
}
PROMPT;

        $response = $this->aiGateway->chat(
            model: AIModel::GPT4oMini,
            messages: [['role' => 'user', 'content' => $prompt]],
            brand: $brand,
            feature: 'campaign_variant'
        );

        $variant = json_decode($response['content'], true);

        return $this->enrichSingleCampaign($variant, $brand);
    }

    /**
     * Retorna campanhas recentes/cacheadas
     */
    public function getRecentIdeas(Brand $brand): array
    {
        // Busca todas as chaves de cache para esta marca
        $pattern = "campaign_ideas:{$brand->id}:*";

        // Como não podemos listar keys no Laravel Cache padrão,
        // usamos o ai_context da marca
        $aiContext = is_array($brand->ai_context) ? $brand->ai_context : [];

        return $aiContext['recent_campaigns'] ?? [];
    }

    /**
     * Salva campanhas recentes
     */
    public function saveRecentIdeas(Brand $brand, array $campaigns): void
    {
        $aiContext = is_array($brand->ai_context) ? $brand->ai_context : [];

        // Mantém apenas as 3 gerações mais recentes
        $recent = $aiContext['recent_campaigns'] ?? [];
        array_unshift($recent, [
            'generated_at' => now()->toIso8601String(),
            'campaigns' => $campaigns,
        ]);
        $recent = array_slice($recent, 0, 3);

        $aiContext['recent_campaigns'] = $recent;

        $brand->update(['ai_context' => $aiContext]);
    }

    /**
     * Gera campanhas usando IA
     */
    private function generateWithAI(Brand $brand, array $brandDNA, ?string $theme, int $count): array
    {
        $prompt = $this->buildCampaignPrompt($brand, $brandDNA, $theme, $count);

        $response = $this->aiGateway->chat(
            model: AIModel::GeminiPro,
            messages: [['role' => 'user', 'content' => $prompt]],
            brand: $brand,
            feature: 'campaign_ideas_generation',
            options: [
                'temperature' => 0.8,
                'max_tokens' => 3500,
            ]
        );

        $result = json_decode($response['content'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("Failed to parse campaign JSON for brand {$brand->id}");
            return [];
        }

        return $result['campaigns'] ?? [];
    }

    /**
     * Constrói prompt de geração de campanhas
     */
    private function buildCampaignPrompt(Brand $brand, array $dna, ?string $theme, int $count): string
    {
        $themeHint = $theme
            ? "FOQUE ESPECIFICAMENTE NO TEMA: {$theme}. Todas as campanhas devem relacionar-se com este tema."
            : "Crie campanhas VARIADAS e CRIATIVAS. Evite temas repetidos ou genéricos.";

        $voiceTone = $dna['voice_tone'] ?? [];
        $messaging = $dna['messaging'] ?? [];
        $target = $dna['target_audience_analysis'] ?? [];
        $strategy = $dna['content_strategy_hints'] ?? [];
        $socialData = $dna['social_data'] ?? [];

        // Prepara contexto de dados sociais
        $socialContext = '';
        if ($socialData['has_social_data'] ?? false) {
            $platformsPerf = [];
            foreach ($socialData['platform_performance'] ?? [] as $platform => $perf) {
                $engagement = $perf['engagement_rate_avg'] ?? 0;
                $trend = $perf['trend_7d'] ?? 0;
                $followers = $perf['followers'] ?? 'N/A';
                $platformsPerf[] = "{$platform}: {$engagement}% engajamento (trend: {$trend}%), {$followers} seguidores";
            }

            $bestTimes = $socialData['optimal_schedule']['best_hours'] ?? ['09:00', '12:00', '18:00'];
            $contentRecs = $socialData['content_recommendations'] ?? [];

            $socialContext = "

=== DADOS REAIS DAS REDES SOCIAIS ===
Contas ativas: " . count($socialData['accounts_summary'] ?? []) . "
Performance por plataforma:
- " . implode("\n- ", $platformsPerf) . "

Melhores horários baseados em dados: " . implode(', ', $bestTimes) . "

Recomendações baseadas em performance:
- " . implode("\n- ", array_slice($contentRecs, 0, 3)) . "

Histórico de posts: " . ($socialData['post_insights']['total_posts'] ?? 0) . " posts nos últimos 90 dias
Categoria de conteúdo dominante: " . ($socialData['post_insights']['content_categories']['dominant_category'] ?? 'variado') . "
";
        }

        return <<<PROMPT
Você é um estrategista sênior de marketing digital com 10 anos de experiência criando campanhas de sucesso.
Sua tarefa é criar {$count} ideias de campanha para redes sociais.

=== CONTEXTO DA MARCA ===
Nome: {$brand->name}
Segmento: {$dna['segment']}

VOZ E PERSONALIDADE:
- Tom: {$voiceTone['tone']}
- Personalidade: {$voiceTone['personality']}
- Estilo: {$voiceTone['communication_style']}
- Formalidade: {$voiceTone['formality_level']}

MENSAGENS-CHAVE:
- Proposta de Valor: {$messaging['value_proposition']}
- Pilares: {$this->arrayToString($messaging['key_messages'] ?? [])}

PÚBLICO-ALVO:
- Demografia: {$target['demographics']}
- Dores: {$this->arrayToString($target['pain_points'] ?? [])}
- Aspirações: {$this->arrayToString($target['aspirations'] ?? [])}

ESTRATÉGIA RECOMENDADA:
- Melhores plataformas: {$this->arrayToString($strategy['best_platforms'] ?? [])}
- Pilares de conteúdo: {$this->arrayToString($strategy['content_pillars'] ?? [])}
{$socialContext}

{$themeHint}

=== REQUISITOS DAS CAMPANHAS ===
Para CADA campanha, forneça:

1. NOME memorável e criativo (máx 40 caracteres)
2. CONCEITO em 2-3 frases persuasivas
3. OBJETIVO claro: awareness | engajamento | conversão | fidelização
4. PLATAFORMAS recomendadas (de Instagram, Facebook, LinkedIn, TikTok, YouTube, Pinterest)
5. FORMATO principal: feed | story | reel | carousel | video
6. 3 VARIAÇÕES de posts, cada uma com:
   - ÂNGULO: Perspectiva única (ex: "por trás das câmeras", "depoimento de cliente", "dica prática")
   - HOOK: Primeira frase de impacto (atenção imediata)
   - CTA: Call-to-action específico
   - DESCRIÇÃO VISUAL: Sugestão do que mostrar na imagem/vídeo

=== VARIEDADE E CRIATIVIDADE ===
- Cada campanha deve ser DIFERENTE das outras
- Misture formatos (educativo, emocional, promocional, entretenimento)
- Considere sazonalidade e datas relevantes
- Inclua pelo menos uma campanha "ousada" ou inovadora
- Inclua pelo menos uma campanha mais segura/tradicional

=== FORMATO DE SAÍDA ===
Retorne APENAS este JSON:
{
  "campaigns": [
    {
      "name": "Nome Criativo",
      "concept": "Conceito completo aqui...",
      "objective": "awareness|engajamento|conversão|fidelização",
      "platforms": ["instagram", "facebook"],
      "format": "carousel",
      "duration": "sugestão de duração (ex: 1 semana, mês de março)",
      "kpis": [" métricas sugeridas"],
      "posts": [
        {
          "angle": "Ângulo único",
          "hook": "Primeira frase impactante",
          "cta": "Ação que o usuário deve tomar",
          "visual_description": "Descrição detalhada da imagem/vídeo sugerido",
          "caption_length": "short|medium|long"
        }
      ]
    }
  ]
}

IMPORTANTE: Gere EXATAMENTE {$count} campanhas de alta qualidade, criativas e acionáveis.
PROMPT;
    }

    /**
     * Constrói prompt para ideia do usuário
     */
    private function buildUserIdeaPrompt(Brand $brand, array $dna, string $userIdea, int $postCount): string
    {
        return <<<PROMPT
Você é um estrategista de marketing. Transforme a ideia do usuário em uma campanha completa.

MARCA: {$brand->name}
Tom: {$dna['voice_tone']['tone']}
Personalidade: {$dna['voice_tone']['personality']}

IDEIA DO USUÁRIO: {$userIdea}

Crie uma campanha com:
1. Nome criativo baseado na ideia
2. Conceito expandido e estruturado
3. Objetivo claro
4. Plataformas recomendadas
5. Formato ideal
6. {$postCount} posts específicos com:
   - Ângulo
   - Hook de atenção
   - CTA
   - Descrição visual

Retorne JSON:
{
  "campaign": {
    "name": "...",
    "concept": "...",
    "objective": "...",
    "platforms": [...],
    "format": "...",
    "posts": [...]
  }
}
PROMPT;
    }

    /**
     * Enriquece campanhas com metadados
     */
    private function enrichCampaigns(array $campaigns, Brand $brand, array $socialData = []): array
    {
        return array_map(fn($campaign) => $this->enrichSingleCampaign($campaign, $brand, $socialData), $campaigns);
    }

    /**
     * Enriquece uma campanha individual
     */
    private function enrichSingleCampaign(array $campaign, Brand $brand, array $socialData = []): array
    {
        // Adiciona IDs únicos
        $campaign['id'] = 'campaign_' . uniqid();

        // Normaliza plataformas
        $campaign['platforms'] = $this->normalizePlatforms($campaign['platforms'] ?? []);

        // Sugere melhores horários (com dados sociais quando disponível)
        $campaign['suggested_times'] = $this->suggestBestTimes($campaign['platforms'], $socialData);

        // Estima esforço
        $campaign['effort_level'] = $this->estimateEffort($campaign);

        // Sugere orçamento (se aplicável)
        $campaign['budget_hint'] = $this->suggestBudgetHint($campaign['objective'] ?? 'awareness');

        // Adiciona dados sociais de contexto
        if ($socialData['has_social_data'] ?? false) {
            $campaign['social_context'] = [
                'engagement_benchmark' => $this->getEngagementBenchmark($campaign['platforms'], $socialData),
                'recommended_hashtags' => $this->getRecommendedHashtags($socialData),
                'content_type_suggestion' => $socialData['post_insights']['content_categories']['dominant_category'] ?? null,
            ];
        }

        // Adiciona timestamps
        $campaign['_generated_at'] = now()->toIso8601String();
        $campaign['_brand_id'] = $brand->id;

        return $campaign;
    }

    /**
     * Normaliza plataformas para valores válidos
     */
    private function normalizePlatforms(array $platforms): array
    {
        $valid = ['instagram', 'facebook', 'linkedin', 'tiktok', 'youtube', 'pinterest', 'twitter'];

        return array_filter(array_map(function ($platform) use ($valid) {
            $normalized = strtolower(trim($platform));
            return in_array($normalized, $valid) ? $normalized : null;
        }, $platforms));
    }

    /**
     * Sugere melhores horários por plataforma (usa dados reais quando disponíveis)
     */
    private function suggestBestTimes(array $platforms, array $socialData = []): array
    {
        // Horários padrão
        $defaultTimes = [
            'instagram' => ['09:00', '12:00', '18:00', '21:00'],
            'facebook' => ['09:00', '13:00', '15:00', '19:00'],
            'linkedin' => ['08:00', '12:00', '17:00'],
            'tiktok' => ['07:00', '12:00', '19:00', '21:00'],
            'youtube' => ['14:00', '16:00', '19:00'],
            'pinterest' => ['20:00', '21:00', '22:00', '23:00'],
            'twitter' => ['08:00', '12:00', '17:00', '19:00'],
        ];

        // Se temos dados sociais com horários otimizados, usamos eles
        if ($socialData['has_social_data'] ?? false) {
            $optimalSchedule = $socialData['optimal_schedule'] ?? [];

            if ($optimalSchedule['based_on_data'] ?? false) {
                $bestHours = $optimalSchedule['best_hours'] ?? [];

                $suggestions = [];
                foreach ($platforms as $platform) {
                    // Usa horários baseados em dados, complementa com padrão se necessário
                    $platformTimes = !empty($bestHours) ? array_slice($bestHours, 0, 3) : ($defaultTimes[$platform] ?? ['12:00']);
                    $suggestions[$platform] = $platformTimes;
                }

                return $suggestions;
            }
        }

        // Fallback para horários padrão
        $suggestions = [];
        foreach ($platforms as $platform) {
            if (isset($defaultTimes[$platform])) {
                $suggestions[$platform] = $defaultTimes[$platform];
            }
        }

        return $suggestions;
    }

    /**
     * Extrai benchmark de engajamento das plataformas
     */
    private function getEngagementBenchmark(array $platforms, array $socialData): array
    {
        $benchmarks = [];
        $performance = $socialData['platform_performance'] ?? [];

        foreach ($platforms as $platform) {
            if (isset($performance[$platform])) {
                $benchmarks[$platform] = [
                    'avg_engagement_rate' => $performance[$platform]['engagement_rate_avg'] ?? 0,
                    'trend' => $performance[$platform]['trend_7d'] ?? 0,
                ];
            }
        }

        return $benchmarks;
    }

    /**
     * Extrai hashtags recomendadas baseadas no histórico
     */
    private function getRecommendedHashtags(array $socialData): array
    {
        $hashtags = [];
        $postInsights = $socialData['post_insights'] ?? [];

        foreach ($postInsights['by_platform'] ?? [] as $platform => $stats) {
            $platformHashtags = $stats['top_hashtags'] ?? [];
            $hashtags = array_merge($hashtags, $platformHashtags);
        }

        return array_slice(array_unique($hashtags), 0, 10);
    }

    /**
     * Estima nível de esforço
     */
    private function estimateEffort(array $campaign): string
    {
        $format = $campaign['format'] ?? 'feed';
        $postCount = count($campaign['posts'] ?? []);

        if ($format === 'video' || $format === 'reel') {
            return $postCount > 3 ? 'high' : 'medium';
        }

        if ($format === 'carousel') {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Sugere orçamento
     */
    private function suggestBudgetHint(string $objective): string
    {
        return match ($objective) {
            'awareness' => 'Investimento em alcance e impressões',
            'engajamento' => 'Foco em interações e comentários',
            'conversão' => 'Orçamento para retargeting e lookalike',
            'fidelização' => 'Investimento moderado, foco em retenção',
            default => 'Definir baseado no objetivo',
        };
    }

    /**
     * Campanhas de fallback em caso de erro
     */
    private function getFallbackCampaigns(Brand $brand, ?string $theme, int $count): array
    {
        $baseCampaigns = [
            [
                'name' => 'Conheça Nossa História',
                'concept' => 'Campanha de storytelling sobre a jornada da marca e valores.',
                'objective' => 'awareness',
                'platforms' => ['instagram', 'facebook'],
                'format' => 'carousel',
                'posts' => [
                    ['angle' => 'Fundação', 'hook' => 'Tudo começou quando...', 'cta' => 'Conheça mais'],
                ],
            ],
            [
                'name' => 'Dicas da Semana',
                'concept' => 'Conteúdo educativo com dicas práticas do seu segmento.',
                'objective' => 'engajamento',
                'platforms' => ['instagram', 'tiktok'],
                'format' => 'feed',
                'posts' => [
                    ['angle' => 'Dica rápida', 'hook' => 'Você sabia que...', 'cta' => 'Salve essa dica'],
                ],
            ],
            [
                'name' => 'Promoção Especial',
                'concept' => 'Oferta por tempo limitado para impulsionar vendas.',
                'objective' => 'conversão',
                'platforms' => ['instagram', 'facebook'],
                'format' => 'story',
                'posts' => [
                    ['angle' => 'Urgência', 'hook' => 'Últimas horas!', 'cta' => 'Aproveite agora'],
                ],
            ],
        ];

        return array_slice($baseCampaigns, 0, $count);
    }

    /**
     * Helper: converte array para string
     */
    private function arrayToString(array $arr): string
    {
        return implode(', ', array_slice($arr, 0, 5));
    }
}

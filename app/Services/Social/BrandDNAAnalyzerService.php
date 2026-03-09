<?php

namespace App\Services\Social;

use App\Enums\AIModel;
use App\Models\Brand;
use App\Services\AI\AIGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Serviço de análise de Brand DNA inspirado no Pomeli
 * Extrai identidade visual e tom de voz automaticamente do site da marca
 */
class BrandDNAAnalyzerService
{
    private const CACHE_TTL = 86400; // 24 horas

    public function __construct(
        private AIGateway $aiGateway
    ) {}

    /**
     * Analisa o site da marca e retorna Brand DNA completo
     */
    public function analyzeBrandFromWebsite(Brand $brand): array
    {
        $cacheKey = "brand_dna:{$brand->id}";

        // Verifica cache primeiro
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            // 1. Extrai conteúdo do site
            $websiteContent = $this->scrapeWebsite($brand->website ?? $this->getFirstUrl($brand));

            // 2. Análise completa via IA
            $analysis = $this->performAIAnalysis($websiteContent, $brand);

            // 3. Enriquece com dados existentes da marca
            $dna = $this->enrichWithExistingData($analysis, $brand);

            // 4. Salva no cache e no banco
            Cache::put($cacheKey, $dna, self::CACHE_TTL);
            $this->saveToBrand($dna, $brand);

            return $dna;
        } catch (\Exception $e) {
            Log::error("Brand DNA Analysis failed for brand {$brand->id}: {$e->getMessage()}", [
                'brand_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);

            // Retorna dados básicos se falhar
            return $this->getBasicBrandDNA($brand);
        }
    }

    /**
     * Retorna análise cacheada ou null
     */
    public function getCachedAnalysis(Brand $brand): ?array
    {
        return Cache::get("brand_dna:{$brand->id}");
    }

    /**
     * Verifica se a marca já tem análise completa
     */
    public function hasCompleteAnalysis(Brand $brand): bool
    {
        $dna = $this->getCachedAnalysis($brand);

        return $dna !== null && !empty($dna['voice_tone']['tone']);
    }

    /**
     * Força reanálise da marca
     */
    public function reanalyzeBrand(Brand $brand): array
    {
        Cache::forget("brand_dna:{$brand->id}");
        return $this->analyzeBrandFromWebsite($brand);
    }

    /**
     * Extrai conteúdo do website via scraping simples
     */
    private function scrapeWebsite(?string $url): string
    {
        if (!$url) {
            return '';
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; BrandAnalyzer/1.0)',
                ])
                ->get($url);

            if (!$response->successful()) {
                return '';
            }

            $html = $response->body();

            // Extrai textos importantes
            return $this->extractImportantContent($html);
        } catch (\Exception $e) {
            Log::warning("Failed to scrape website {$url}: {$e->getMessage()}");
            return '';
        }
    }

    /**
     * Extrai conteúdo importante do HTML
     */
    private function extractImportantContent(string $html): string
    {
        // Remove scripts e styles
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);

        // Extrai meta tags importantes
        $metaContent = '';
        if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $matches)) {
            $metaContent .= "Meta Description: {$matches[1]}\n";
        }
        if (preg_match('/<meta[^>]*property=["\']og:description["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $matches)) {
            $metaContent .= "OG Description: {$matches[1]}\n";
        }

        // Extrai títulos
        $headings = '';
        if (preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches)) {
            foreach ($matches[1] as $h1) {
                $headings .= "H1: " . strip_tags($h1) . "\n";
            }
        }
        if (preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $html, $matches)) {
            foreach (array_slice($matches[1], 0, 5) as $h2) {
                $headings .= "H2: " . strip_tags($h2) . "\n";
            }
        }

        // Extrai parágrafos
        $paragraphs = '';
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $matches)) {
            $texts = array_slice($matches[1], 0, 10);
            foreach ($texts as $p) {
                $text = trim(strip_tags($p));
                if (strlen($text) > 30) {
                    $paragraphs .= $text . "\n\n";
                }
            }
        }

        // Extrai texto de seções de about/sobre
        $aboutSection = '';
        if (preg_match('/<(section|div)[^>]*(?:id|class)=["\'][^"\']*(?:sobre|about|quem-somos|nossa-historia)[^"\']*["\'][^>]*>(.*?)<\/\1>/is', $html, $matches)) {
            $aboutSection = strip_tags($matches[2]);
        }

        // Extrai CTA buttons/links
        $ctas = '';
        if (preg_match_all('/<a[^>]*>(.*?(?:comprar|saiba mais|conheça|veja|confira|quero|assinar|cadastrar).*?)<\/a>/is', $html, $matches)) {
            foreach (array_slice($matches[1], 0, 5) as $cta) {
                $ctas .= "CTA: " . strip_tags($cta) . "\n";
            }
        }

        $content = trim($metaContent . "\n" . $headings . "\n" . $aboutSection . "\n" . $paragraphs . "\n" . $ctas);

        // Limita tamanho para não exceder tokens
        return mb_substr($content, 0, 8000);
    }

    /**
     * Realiza análise completa via IA
     */
    private function performAIAnalysis(string $content, Brand $brand): array
    {
        $prompt = $this->buildAnalysisPrompt($content, $brand);

        $response = $this->aiGateway->chat(
            model: AIModel::GeminiPro,
            messages: [
                ['role' => 'user', 'content' => $prompt]
            ],
            brand: $brand,
            feature: 'brand_dna_analysis',
            options: [
                'temperature' => 0.3,
                'max_tokens' => 2048,
            ]
        );

        $result = json_decode($response['content'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("Failed to parse AI analysis JSON for brand {$brand->id}");
            return [];
        }

        return $result;
    }

    /**
     * Constrói o prompt de análise
     */
    private function buildAnalysisPrompt(string $content, Brand $brand): string
    {
        return <<<PROMPT
Você é um especialista sênior em branding, marketing digital e análise de marcas com 15 anos de experiência.
Sua tarefa é analisar profundamente o conteúdo do site e extrair o "Brand DNA" - a essência única desta marca.

MARCA: {$brand->name}
SEGMENTO INFORMADO: {$brand->segment}
PÚBLICO-ALVO INFORMADO: {$brand->target_audience}

CONTEÚDO EXTRAÍDO DO SITE:
---
{$content}
---

Realize uma análise profunda e retorne um JSON estruturado com:

{
  "voice_tone": {
    "tone": "Descrição específica do tom (ex: descontraído e amigável, formal e técnico, luxuoso e sofisticado)",
    "personality": "Se a marca fosse uma pessoa, como seria? (ex: o amigo que entende de tecnologia, o consultor experiente, o artista criativo)",
    "communication_style": "Estilo de comunicação (ex: direto e objetivo, storytelling emotivo, educativo didático)",
    "formality_level": "Formal/Neutro/Informal",
    "emotion_level": "Alta/Média/Baixa - quão emotiva é a comunicação",
    "humor": "Sim/Não - a marca usa humor? Que tipo?"
  },
  "visual_identity": {
    "style_keywords": ["3-5 palavras que descrevem o estilo visual (ex: minimalista, colorido, profissional, artsy)"],
    "perceived_colors": "Quais cores predominam no site? (descreva)",
    "image_style": "Estilo de imagens usado (fotos reais, ilustrações, 3D, etc)"
  },
  "messaging": {
    "value_proposition": "Proposta de valor principal em 1 frase",
    "key_messages": ["3-5 mensagens-chave que a marca transmite"],
    "tagline_style": "Estilo de slogans usados (se houver)",
    "pain_points_addressed": ["2-3 problemas que a marca resolve para clientes"],
    "benefits_highlighted": ["3-5 benefícios destacados"]
  },
  "target_audience_analysis": {
    "demographics": "Descrição demográfica estimada (idade, renda, localização)",
    "psychographics": "Interesses, valores, comportamentos",
    "pain_points": ["Dores específicas do público"],
    "aspirations": ["O que o público almeja"]
  },
  "competitive_positioning": {
    "market_position": "Posicionamento (premium, acessível, inovador, tradicional)",
    "differentiators": ["2-3 diferenciais únicos da marca"],
    "brand_archetype": "Arquétipo de marca (ex: Mago, Herói, Criador, Cuidador)"
  },
  "content_strategy_hints": {
    "best_platforms": ["Redes sociais mais adequadas"],
    "content_pillars": ["3-5 pilares de conteúdo sugeridos"],
    "posting_style": "Estilo de posts recomendado",
    "engagement_approach": "Como a marca deve engajar o público"
  }
}

INSTRUÇÕES IMPORTANTES:
1. Seja específico e acionável - evite descrições genéricas
2. Baseie-se no conteúdo real do site, não suposições
3. Se o conteúdo for limitado, faça inferências razoáveis
4. Mantenha consistência entre todas as seções
5. Use linguagem profissional de marketing
6. Retorne APENAS o JSON, sem explicações adicionais
PROMPT;
    }

    /**
     * Enriquece dados com informações existentes da marca
     */
    private function enrichWithExistingData(array $analysis, Brand $brand): array
    {
        // Mescla com dados existentes
        if ($brand->segment) {
            $analysis['segment'] = $brand->segment;
        }

        if ($brand->primary_color) {
            $analysis['colors'] = [
                'primary' => $brand->primary_color,
                'secondary' => $brand->secondary_color,
                'accent' => $brand->accent_color,
            ];
        }

        if (is_array($brand->keywords) && !empty($brand->keywords)) {
            $analysis['keywords'] = $brand->keywords;
        }

        // Adiciona metadados
        $analysis['_meta'] = [
            'analyzed_at' => now()->toIso8601String(),
            'brand_id' => $brand->id,
            'source' => $brand->website ?? 'fallback',
        ];

        return $analysis;
    }

    /**
     * Salva análise na marca
     */
    private function saveToBrand(array $dna, Brand $brand): void
    {
        $aiContext = is_array($brand->ai_context) ? $brand->ai_context : [];
        $aiContext['brand_dna_analysis'] = $dna;
        $aiContext['brand_dna_updated_at'] = now()->toIso8601String();

        $brand->update([
            'ai_context' => $aiContext,
        ]);
    }

    /**
     * Retorna Brand DNA básico em caso de falha
     */
    private function getBasicBrandDNA(Brand $brand): array
    {
        return [
            'voice_tone' => [
                'tone' => $brand->tone_of_voice ?: 'profissional',
                'personality' => 'Marca profissional',
                'formality_level' => 'Neutro',
            ],
            'messaging' => [
                'value_proposition' => "{$brand->name} - {$brand->segment}",
                'key_messages' => [],
            ],
            'target_audience_analysis' => [
                'demographics' => $brand->target_audience ?: 'Geral',
            ],
            '_meta' => [
                'analyzed_at' => now()->toIso8601String(),
                'brand_id' => $brand->id,
                'source' => 'fallback_basic',
            ],
        ];
    }

    /**
     * Obtém primeira URL disponível da marca
     */
    private function getFirstUrl(Brand $brand): ?string
    {
        if ($brand->website) {
            return $brand->website;
        }

        $urls = is_array($brand->urls) ? $brand->urls : [];
        foreach ($urls as $url) {
            if (!empty($url['url'])) {
                return $url['url'];
            }
        }

        return null;
    }
}

<?php

namespace App\Services\Email;

use App\Models\Brand;
use App\Models\EmailAiSuggestion;
use App\Models\SocialInsight;
use App\Models\SocialAccount;
use App\Models\Setting;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmailAiSuggestionService
{
    /**
     * Gera sugestões de email marketing para uma marca
     * baseado no histórico de redes sociais, links e contexto da marca
     */
    public function generateForBrand(Brand $brand): int
    {
        $apiKey = Setting::get('api_keys', 'gemini_api_key');
        if (!$apiKey) {
            Log::warning("EmailAiSuggestion: Sem API key Gemini para gerar sugestões");
            return 0;
        }

        // 1. Coletar contexto
        $context = $this->gatherBrandContext($brand);

        // 2. Montar prompt
        $prompt = $this->buildPrompt($brand, $context);

        // 3. Chamar IA
        try {
            $response = Http::timeout(90)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}",
                [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.8,
                        'maxOutputTokens' => 4096,
                    ],
                ]
            );

            if (!$response->successful()) {
                SystemLog::error('email', 'ai_suggestion.api_error', 'Gemini API falhou', [
                    'brand_id' => $brand->id,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
                return 0;
            }

            $text = $response->json('candidates.0.content.parts.0.text', '');

            // 4. Parsear resultado
            $suggestions = $this->parseAiResponse($text);

            // 5. Salvar no banco
            $count = 0;
            foreach ($suggestions as $suggestion) {
                EmailAiSuggestion::create([
                    'brand_id' => $brand->id,
                    'title' => $suggestion['title'] ?? 'Sugestão de Email',
                    'description' => $suggestion['description'] ?? null,
                    'suggested_subject' => $suggestion['subject'] ?? null,
                    'suggested_preview' => $suggestion['preview'] ?? null,
                    'target_audience' => $suggestion['audience'] ?? null,
                    'content_type' => $suggestion['type'] ?? 'newsletter',
                    'suggested_send_date' => $suggestion['send_date'] ?? now()->addDays(rand(1, 7))->format('Y-m-d'),
                    'reference_data' => [
                        'brand_context' => $context,
                        'ai_model' => 'gemini-2.0-flash',
                        'generated_at' => now()->toISOString(),
                    ],
                    'status' => 'pending',
                ]);
                $count++;
            }

            SystemLog::info('email', 'ai_suggestion.generated', "Geradas {$count} sugestões para marca {$brand->name}", [
                'brand_id' => $brand->id,
                'count' => $count,
            ]);

            return $count;
        } catch (\Throwable $e) {
            SystemLog::error('email', 'ai_suggestion.error', $e->getMessage(), [
                'brand_id' => $brand->id,
            ]);
            return 0;
        }
    }

    /**
     * Gera sugestões para todas as marcas
     */
    public function generateForAllBrands(): int
    {
        $total = 0;
        $brands = Brand::all();

        foreach ($brands as $brand) {
            try {
                $count = $this->generateForBrand($brand);
                $total += $count;
                // Delay entre marcas para não sobrecarregar a API
                sleep(2);
            } catch (\Throwable $e) {
                Log::error("AI suggestion generation failed for brand {$brand->id}", ['error' => $e->getMessage()]);
            }
        }

        return $total;
    }

    /**
     * Coleta todo o contexto necessário da marca
     */
    private function gatherBrandContext(Brand $brand): array
    {
        $context = [
            'brand_name' => $brand->name,
            'segment' => $brand->segment ?? 'Não informado',
            'tone' => $brand->tone_of_voice ?? 'Profissional',
            'description' => $brand->description ?? '',
            'target_audience' => $brand->target_audience ?? '',
        ];

        // Posts recentes das redes sociais
        $socialAccounts = SocialAccount::where('brand_id', $brand->id)
            ->where('is_active', true)
            ->get();

        $recentPosts = [];
        $socialMetrics = [];

        foreach ($socialAccounts as $account) {
            $insights = SocialInsight::where('social_account_id', $account->id)
                ->latest()
                ->first();

            if ($insights) {
                $socialMetrics[] = [
                    'platform' => $account->platform,
                    'followers' => $insights->followers ?? 0,
                    'reach' => $insights->reach ?? 0,
                    'engagement' => $insights->engagement_rate ?? 0,
                ];
            }

            // Buscar posts recentes se disponível
            $posts = \App\Models\Post::where('brand_id', $brand->id)
                ->where('status', 'published')
                ->latest()
                ->limit(5)
                ->get(['title', 'content', 'platform', 'published_at']);

            foreach ($posts as $post) {
                $recentPosts[] = [
                    'title' => $post->title ?? '',
                    'content' => substr($post->content ?? '', 0, 200),
                    'platform' => $post->platform ?? '',
                    'date' => $post->published_at?->format('d/m/Y') ?? '',
                ];
            }
        }

        $context['social_metrics'] = $socialMetrics;
        $context['recent_posts'] = array_slice($recentPosts, 0, 10);

        // Links da marca (se existir o model)
        $context['brand_links'] = [];
        try {
            if (class_exists(\App\Models\BrandLink::class)) {
                $links = \App\Models\BrandLink::where('brand_id', $brand->id)
                    ->get(['title', 'url', 'description']);
                $context['brand_links'] = $links->toArray();
            }
        } catch (\Throwable $e) {
            // BrandLink model pode não existir
        }

        // Campanhas recentes de email
        $recentCampaigns = \App\Models\EmailCampaign::where('brand_id', $brand->id)
            ->whereIn('status', ['sent', 'sending'])
            ->latest()
            ->limit(5)
            ->get(['name', 'subject', 'total_sent', 'unique_opens', 'unique_clicks', 'started_at']);

        $context['recent_campaigns'] = $recentCampaigns->map(fn($c) => [
            'name' => $c->name,
            'subject' => $c->subject,
            'sent' => $c->total_sent,
            'opens' => $c->unique_opens,
            'clicks' => $c->unique_clicks,
            'open_rate' => $c->total_sent > 0 ? round(($c->unique_opens / $c->total_sent) * 100, 1) : 0,
            'date' => $c->started_at?->format('d/m/Y'),
        ])->toArray();

        $mascots = $brand->mascots()->get();
        $context['has_mascot'] = $mascots->isNotEmpty();
        $context['mascot_names'] = $mascots->pluck('label')->filter()->values()->toArray();

        $products = $brand->products()->get();
        $context['has_products'] = $products->isNotEmpty();
        $context['product_names'] = $products->pluck('label')->filter()->values()->toArray();
        $context['product_count'] = $products->count();

        return $context;
    }

    /**
     * Monta o prompt para a IA
     */
    private function buildPrompt(Brand $brand, array $context): string
    {
        $today = now()->format('d/m/Y');
        $dayOfWeek = now()->locale('pt_BR')->dayName;

        $prompt = "Você é um estrategista de email marketing expert. Analise o contexto abaixo e sugira 3-5 pautas de email marketing criativas e relevantes para a marca.\n\n";

        $prompt .= "=== CONTEXTO DA MARCA ===\n";
        $prompt .= "Nome: {$context['brand_name']}\n";
        $prompt .= "Segmento: {$context['segment']}\n";
        $prompt .= "Tom de voz: {$context['tone']}\n";
        if ($context['description']) $prompt .= "Descrição: {$context['description']}\n";
        if ($context['target_audience']) $prompt .= "Público-alvo: {$context['target_audience']}\n";

        if (!empty($context['social_metrics'])) {
            $prompt .= "\n=== MÉTRICAS SOCIAIS ===\n";
            foreach ($context['social_metrics'] as $m) {
                $prompt .= "- {$m['platform']}: {$m['followers']} seguidores, alcance: {$m['reach']}, engajamento: {$m['engagement']}%\n";
            }
        }

        if (!empty($context['recent_posts'])) {
            $prompt .= "\n=== POSTS RECENTES NAS REDES ===\n";
            foreach ($context['recent_posts'] as $p) {
                $prompt .= "- [{$p['platform']}] {$p['title']}: {$p['content']}\n";
            }
        }

        if (!empty($context['brand_links'])) {
            $prompt .= "\n=== LINKS/PÁGINAS DA MARCA ===\n";
            foreach ($context['brand_links'] as $l) {
                $prompt .= "- {$l['title'] ?? ''}: {$l['url'] ?? ''} - {$l['description'] ?? ''}\n";
            }
        }

        if (!empty($context['recent_campaigns'])) {
            $prompt .= "\n=== CAMPANHAS DE EMAIL RECENTES ===\n";
            foreach ($context['recent_campaigns'] as $c) {
                $prompt .= "- \"{$c['subject']}\" ({$c['date']}): {$c['sent']} envios, {$c['open_rate']}% abertura\n";
            }
        }

        if (!empty($context['has_mascot'])) {
            $mascotNames = implode(', ', $context['mascot_names']);
            $prompt .= "\n=== MASCOTE DA MARCA ===\n";
            $prompt .= "A marca possui mascote/personagem: {$mascotNames}. Considere usar o mascote em pelo menos 1 sugestão (storytelling, humanização, humor).\n";
        }

        if (!empty($context['has_products'])) {
            $productNames = implode(', ', $context['product_names']);
            $prompt .= "\n=== PRODUTOS CADASTRADOS ({$context['product_count']}) ===\n";
            $prompt .= "Produtos: {$productNames}. Considere incluir pelo menos 1 sugestão focada em produto (destaque, lançamento, promoção, review).\n";
        }

        $prompt .= "\n=== INSTRUÇÕES ===\n";
        $prompt .= "Hoje é {$dayOfWeek}, {$today}.\n";
        $prompt .= "Gere 3-5 sugestões de emails que a marca deveria enviar nos próximos dias.\n";
        $prompt .= "Para cada sugestão, considere:\n";
        $prompt .= "- Datas relevantes/sazonalidades\n";
        $prompt .= "- Conteúdo que performou bem nas redes sociais\n";
        $prompt .= "- Links e páginas disponíveis da marca\n";
        $prompt .= "- Evitar repetir assuntos de campanhas recentes\n";
        $prompt .= "- Variar entre tipos: newsletter, promocional, educacional, engajamento, sazonal\n";
        $prompt .= "- Se a marca tem mascote, use-o para humanizar e engajar em pelo menos 1 email\n";
        $prompt .= "- Se a marca tem produtos cadastrados, crie sugestões que destaquem esses produtos\n\n";

        $prompt .= "Responda APENAS com um JSON array, SEM formatação markdown, no seguinte formato:\n";
        $prompt .= '[{"title":"Titulo da pauta","description":"Descrição completa do conteúdo sugerido, com ideias de seções e abordagem","subject":"Linha de assunto sugerida","preview":"Texto de preview do email","audience":"Público-alvo específico","type":"newsletter|promotional|educational|seasonal|engagement","send_date":"YYYY-MM-DD"}]';

        return $prompt;
    }

    /**
     * Parseia a resposta da IA
     */
    private function parseAiResponse(string $text): array
    {
        // Limpar formatação markdown
        $text = preg_replace('/^```(?:json)?\s*\n?/m', '', $text);
        $text = preg_replace('/\n?```\s*$/m', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);

        if (is_array($decoded) && !empty($decoded)) {
            // Validar estrutura
            return array_filter($decoded, fn($item) =>
                is_array($item) && !empty($item['title'] ?? null)
            );
        }

        // Tentar extrair JSON de dentro do texto
        if (preg_match('/\[[\s\S]*\]/', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return array_filter($decoded, fn($item) =>
                    is_array($item) && !empty($item['title'] ?? null)
                );
            }
        }

        Log::warning("EmailAiSuggestion: Could not parse AI response", ['text' => substr($text, 0, 500)]);
        return [];
    }
}

<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Models\EmailAiSuggestion;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateSmsAiSuggestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function handle(): void
    {
        $apiKey = Setting::get('api_keys', 'gemini_api_key');
        if (!$apiKey) {
            Log::warning("SmsAiSuggestion: Sem API key Gemini");
            return;
        }

        $total = 0;
        $brands = Brand::all();

        foreach ($brands as $brand) {
            try {
                $count = $this->generateForBrand($brand, $apiKey);
                $total += $count;
                sleep(2);
            } catch (\Throwable $e) {
                Log::error("SMS AI suggestion failed for brand {$brand->id}", ['error' => $e->getMessage()]);
            }
        }

        SystemLog::info('sms', 'ai_suggestions.daily', "Job diário SMS: {$total} sugestões geradas para todas as marcas", [
            'total_suggestions' => $total,
        ]);
    }

    private function generateForBrand(Brand $brand, string $apiKey): int
    {
        // Coletar contexto
        $context = $this->gatherContext($brand);

        // Montar prompt
        $prompt = $this->buildPrompt($brand, $context);

        $response = Http::timeout(90)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}",
            [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.8, 'maxOutputTokens' => 3000],
            ]
        );

        if (!$response->successful()) {
            SystemLog::error('sms', 'ai_suggestion.api_error', 'Gemini API falhou para SMS', [
                'brand_id' => $brand->id,
                'status' => $response->status(),
            ]);
            return 0;
        }

        $text = $response->json('candidates.0.content.parts.0.text', '');
        $suggestions = $this->parseResponse($text);

        $count = 0;
        foreach ($suggestions as $s) {
            EmailAiSuggestion::create([
                'brand_id' => $brand->id,
                'title' => $s['title'] ?? 'Sugestão SMS',
                'description' => $s['description'] ?? null,
                'suggested_subject' => $s['body'] ?? null, // Corpo do SMS
                'suggested_preview' => mb_substr($s['body'] ?? '', 0, 100),
                'target_audience' => $s['audience'] ?? null,
                'content_type' => 'sms', // Diferenciador
                'suggested_send_date' => $s['send_date'] ?? now()->addDays(rand(1, 7))->format('Y-m-d'),
                'reference_data' => [
                    'channel' => 'sms',
                    'ai_model' => 'gemini-2.0-flash',
                    'generated_at' => now()->toISOString(),
                    'segments' => \App\Models\SmsTemplate::calculateSegments($s['body'] ?? ''),
                ],
                'status' => 'pending',
            ]);
            $count++;
        }

        return $count;
    }

    private function gatherContext(Brand $brand): array
    {
        $context = [
            'brand_name' => $brand->name,
            'segment' => $brand->segment ?? 'Não informado',
            'tone' => $brand->tone_of_voice ?? 'Profissional',
            'description' => $brand->description ?? '',
        ];

        // Insights de redes sociais
        $accounts = SocialAccount::where('brand_id', $brand->id)->where('is_active', true)->get();
        $socialData = [];
        foreach ($accounts as $account) {
            $insight = SocialInsight::where('social_account_id', $account->id)->latest()->first();
            if ($insight) {
                $socialData[] = "{$account->platform}: {$insight->followers} seguidores";
            }
        }
        $context['social'] = implode(', ', $socialData);

        // Links cadastrados na marca
        $links = $brand->links ?? [];
        $context['links'] = collect($links)->map(fn($l) => ($l['label'] ?? '') . ': ' . ($l['url'] ?? ''))->implode(', ');

        return $context;
    }

    private function buildPrompt(Brand $brand, array $context): string
    {
        return <<<PROMPT
Você é um especialista em SMS Marketing para o mercado brasileiro.

MARCA: {$context['brand_name']}
SEGMENTO: {$context['segment']}
TOM: {$context['tone']}
DESCRIÇÃO: {$context['description']}
REDES SOCIAIS: {$context['social']}
LINKS: {$context['links']}

TAREFA: Gere 3 sugestões de campanhas SMS para esta marca.

REGRAS:
- Cada SMS deve ter no MÁXIMO 160 caracteres (GSM-7) para caber em 1 segmento
- Use linguagem direta, concisa e com CTA claro
- Inclua {{first_name}} para personalização
- Inclua {{sms_optout}} como merge tag de opt-out (LGPD)
- Adapte ao tom de voz e segmento da marca
- Crie campanhas variadas: promoção, engajamento, lembrete
- Considere o contexto das redes sociais para manter coerência
- Responda APENAS em formato JSON válido

FORMATO DE RESPOSTA (JSON array):
[
  {
    "title": "Nome da campanha",
    "description": "Breve descrição da estratégia",
    "body": "Texto completo do SMS com merge tags",
    "audience": "Público-alvo sugerido",
    "send_date": "YYYY-MM-DD"
  }
]
PROMPT;
    }

    private function parseResponse(string $text): array
    {
        // Tentar extrair JSON do texto
        $text = trim($text);

        // Remover markdown code block se presente
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Tentar extrair array de dentro do texto
        if (preg_match('/\[[\s\S]*\]/', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) return $decoded;
        }

        return [];
    }
}

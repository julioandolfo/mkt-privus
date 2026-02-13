<?php

namespace App\Services\Sms;

use App\Models\EmailProvider;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsProviderService
{
    private const API_BASE = 'https://api.sendpulse.com';

    /**
     * Obtém access token do SendPulse (com cache de 1h)
     */
    public function getAccessToken(EmailProvider $provider): ?string
    {
        $cacheKey = "sms_sendpulse_token_{$provider->id}";

        return Cache::remember($cacheKey, 3500, function () use ($provider) {
            $config = $provider->config;

            $response = Http::post(self::API_BASE . '/oauth/access_token', [
                'grant_type' => 'client_credentials',
                'client_id' => $config['api_user_id'],
                'client_secret' => $config['api_secret'],
            ]);

            if ($response->successful() && $response->json('access_token')) {
                return $response->json('access_token');
            }

            Log::error('SMS SendPulse auth failed', [
                'provider_id' => $provider->id,
                'response' => $response->body(),
            ]);

            return null;
        });
    }

    /**
     * Envia SMS individual
     */
    public function sendSms(
        EmailProvider $provider,
        string $phone,
        string $body,
        string $senderName
    ): array {
        $token = $this->getAccessToken($provider);
        if (!$token) {
            return ['success' => false, 'error' => 'Falha na autenticação SMS SendPulse'];
        }

        // Validar telefone
        $cleanPhone = $this->cleanPhone($phone);
        if (!$this->isValidPhone($cleanPhone)) {
            return ['success' => false, 'error' => "Número de telefone inválido: {$phone}"];
        }

        $response = Http::withToken($token)
            ->post(self::API_BASE . '/sms/send', [
                'sender' => $senderName,
                'phones' => [$cleanPhone],
                'body' => $body,
            ]);

        if ($response->successful()) {
            $data = $response->json();
            return [
                'success' => true,
                'campaign_id' => $data['id'] ?? null,
                'result' => $data,
            ];
        }

        return [
            'success' => false,
            'error' => 'SendPulse SMS: ' . ($response->json('message') ?? $response->body()),
        ];
    }

    /**
     * Envia SMS em lote (várias phones de uma vez)
     */
    public function sendBulk(
        EmailProvider $provider,
        array $phones,
        string $body,
        string $senderName,
        ?string $route = null
    ): array {
        $token = $this->getAccessToken($provider);
        if (!$token) {
            return ['success' => false, 'error' => 'Falha na autenticação SMS SendPulse'];
        }

        // Validar e limpar telefones
        $validPhones = [];
        $invalidPhones = [];

        foreach ($phones as $phone) {
            $clean = $this->cleanPhone($phone);
            if ($this->isValidPhone($clean)) {
                $validPhones[] = $clean;
            } else {
                $invalidPhones[] = $phone;
            }
        }

        if (empty($validPhones)) {
            return ['success' => false, 'error' => 'Nenhum telefone válido encontrado'];
        }

        $payload = [
            'sender' => $senderName,
            'phones' => $validPhones,
            'body' => $body,
        ];

        if ($route) {
            $payload['route'] = $route;
        }

        $response = Http::withToken($token)
            ->post(self::API_BASE . '/sms/send', $payload);

        if ($response->successful()) {
            $data = $response->json();
            return [
                'success' => true,
                'campaign_id' => $data['id'] ?? null,
                'total_valid' => count($validPhones),
                'total_invalid' => count($invalidPhones),
                'invalid_phones' => $invalidPhones,
                'result' => $data,
            ];
        }

        return [
            'success' => false,
            'error' => 'SendPulse SMS bulk: ' . ($response->json('message') ?? $response->body()),
        ];
    }

    /**
     * Busca informação de preço/saldo SMS
     */
    public function getBalance(EmailProvider $provider): array
    {
        $token = $this->getAccessToken($provider);
        if (!$token) {
            return ['success' => false, 'error' => 'Falha na autenticação'];
        }

        $response = Http::withToken($token)
            ->get(self::API_BASE . '/sms/numbers');

        if ($response->successful()) {
            return ['success' => true, 'data' => $response->json()];
        }

        return ['success' => false, 'error' => $response->body()];
    }

    /**
     * Busca status de uma campanha SMS
     */
    public function getCampaignStatus(EmailProvider $provider, string $campaignId): array
    {
        $token = $this->getAccessToken($provider);
        if (!$token) {
            return ['success' => false, 'error' => 'Falha na autenticação'];
        }

        $response = Http::withToken($token)
            ->get(self::API_BASE . "/sms/campaigns/{$campaignId}");

        if ($response->successful()) {
            return ['success' => true, 'data' => $response->json()];
        }

        return ['success' => false, 'error' => $response->body()];
    }

    /**
     * Testa a conexão com SendPulse SMS
     */
    public function testConnection(EmailProvider $provider): array
    {
        $token = $this->getAccessToken($provider);

        if ($token) {
            // Tenta buscar saldo para validar
            $balance = $this->getBalance($provider);
            return [
                'success' => true,
                'message' => 'Conexão SMS SendPulse OK.',
                'balance' => $balance['data'] ?? null,
            ];
        }

        return ['success' => false, 'error' => 'Falha na autenticação SMS'];
    }

    /**
     * Estima custo do envio baseado no país (BR)
     * Custo médio por SMS no Brasil: ~R$0,08 a R$0,12
     */
    public function estimateCost(int $totalRecipients, int $segments, string $currency = 'BRL'): array
    {
        // Preços médios por país (por segmento)
        $pricePerSegment = match ($currency) {
            'BRL' => 0.085,  // ~R$ 0,085 por segmento SMS Brasil
            'USD' => 0.015,
            default => 0.085,
        };

        $totalSegments = $totalRecipients * $segments;
        $totalCost = round($totalSegments * $pricePerSegment, 4);

        return [
            'total_recipients' => $totalRecipients,
            'segments_per_msg' => $segments,
            'total_segments' => $totalSegments,
            'price_per_segment' => $pricePerSegment,
            'estimated_cost' => $totalCost,
            'currency' => $currency,
            'formatted' => $currency === 'BRL'
                ? 'R$ ' . number_format($totalCost, 2, ',', '.')
                : '$ ' . number_format($totalCost, 2, '.', ','),
        ];
    }

    /**
     * Limpa número de telefone, mantendo apenas dígitos e +
     */
    public function cleanPhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9+]/', '', $phone);

        // Se não começa com +, assumir Brasil (+55)
        if (!str_starts_with($cleaned, '+')) {
            // Se já começa com 55 e tem 12-13 dígitos, adicionar +
            if (str_starts_with($cleaned, '55') && strlen($cleaned) >= 12) {
                $cleaned = '+' . $cleaned;
            }
            // Se tem 10-11 dígitos, assumir DDD+numero brasileiro
            elseif (strlen($cleaned) >= 10 && strlen($cleaned) <= 11) {
                $cleaned = '+55' . $cleaned;
            }
        }

        return $cleaned;
    }

    /**
     * Valida formato de telefone (internacional E.164)
     */
    public function isValidPhone(string $phone): bool
    {
        // E.164: + seguido de 8 a 15 dígitos
        return (bool) preg_match('/^\+[1-9]\d{7,14}$/', $phone);
    }

    /**
     * Adiciona texto de opt-out ao corpo da mensagem
     */
    public function appendOptOut(string $body, string $optOutText = 'Resp. SAIR p/ cancelar'): string
    {
        // Se já tem opt-out ({{sms_optout}} merge tag), substituir
        if (str_contains($body, '{{sms_optout}}')) {
            return str_replace('{{sms_optout}}', $optOutText, $body);
        }

        // Senão, adicionar ao final
        return trim($body) . "\n" . $optOutText;
    }

    /**
     * Substitui merge tags no corpo da mensagem
     */
    public function replaceMergeTags(string $body, array $contactData): string
    {
        $replacements = [
            '{{first_name}}' => $contactData['first_name'] ?? '',
            '{{last_name}}' => $contactData['last_name'] ?? '',
            '{{name}}' => trim(($contactData['first_name'] ?? '') . ' ' . ($contactData['last_name'] ?? '')),
            '{{email}}' => $contactData['email'] ?? '',
            '{{phone}}' => $contactData['phone'] ?? '',
            '{{company}}' => $contactData['company'] ?? '',
            '{{date}}' => now()->format('d/m/Y'),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $body);
    }
}

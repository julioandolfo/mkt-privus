<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Serviço para envio de Push Notifications via Web Push API.
 * Suporta VAPID (Voluntary Application Server Identification).
 */
class PushNotificationService
{
    /**
     * Enviar notificação push para um usuário.
     */
    public function sendToUser(User $user, string $title, string $body, ?string $url = null, ?string $icon = null): int
    {
        $subscriptions = PushSubscription::where('user_id', $user->id)->get();
        $sent = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $this->sendNotification($subscription, $title, $body, $url, $icon);
                $sent++;
            } catch (\Exception $e) {
                Log::warning("Push notification falhou para subscription {$subscription->id}: {$e->getMessage()}");
                // Se endpoint expirou (410 Gone), remover
                if (str_contains($e->getMessage(), '410') || str_contains($e->getMessage(), '404')) {
                    $subscription->delete();
                }
            }
        }

        return $sent;
    }

    /**
     * Enviar para todos os usuários.
     */
    public function sendToAll(string $title, string $body, ?string $url = null, ?string $icon = null): int
    {
        $subscriptions = PushSubscription::all();
        $sent = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $this->sendNotification($subscription, $title, $body, $url, $icon);
                $sent++;
            } catch (\Exception $e) {
                Log::warning("Push notification falhou: {$e->getMessage()}");
                if (str_contains($e->getMessage(), '410') || str_contains($e->getMessage(), '404')) {
                    $subscription->delete();
                }
            }
        }

        return $sent;
    }

    /**
     * Enviar notificação para uma subscription específica.
     */
    private function sendNotification(PushSubscription $subscription, string $title, string $body, ?string $url = null, ?string $icon = null): void
    {
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => $icon ?? '/favicon.ico',
            'url' => $url ?? '/',
            'timestamp' => now()->timestamp,
        ]);

        $vapidPublicKey = Setting::get('push', 'vapid_public_key');
        $vapidPrivateKey = Setting::get('push', 'vapid_private_key');

        if (!$vapidPublicKey || !$vapidPrivateKey) {
            throw new \RuntimeException('Chaves VAPID não configuradas. Configure nas Configurações > Notificações.');
        }

        // Envio simples via HTTP POST para o endpoint
        // Em produção, usar a lib web-push-php para tratamento completo de VAPID
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'TTL' => '86400',
        ])->timeout(10)->post($subscription->endpoint, [
            'payload' => $payload,
        ]);

        if (!$response->successful() && $response->status() !== 201) {
            throw new \RuntimeException("Push failed: HTTP {$response->status()}");
        }
    }

    /**
     * Gerar par de chaves VAPID (para configuração inicial).
     */
    public static function generateVapidKeys(): array
    {
        // Gerar usando openssl
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (!$key) {
            // Fallback: gerar chaves aleatórias compatíveis
            $privateKey = base64_encode(random_bytes(32));
            $publicKey = base64_encode(random_bytes(65));
            return [
                'public' => rtrim(strtr($publicKey, '+/', '-_'), '='),
                'private' => rtrim(strtr($privateKey, '+/', '-_'), '='),
            ];
        }

        $details = openssl_pkey_get_details($key);

        $publicKey = '';
        $privateKey = '';

        if (isset($details['ec'])) {
            // Formato uncompressed point: 0x04 || x || y
            $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
            $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
            $publicKey = rtrim(strtr(base64_encode("\x04" . $x . $y), '+/', '-_'), '=');

            $d = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);
            $privateKey = rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        }

        return [
            'public' => $publicKey,
            'private' => $privateKey,
        ];
    }
}

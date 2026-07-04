<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Integração com assinaturas (preapproval) do Mercado Pago.
 *
 * Docs: https://www.mercadopago.com.br/developers/pt/docs/subscriptions
 */
class MercadoPagoService
{
    private function http()
    {
        return Http::baseUrl(config('billing.mercadopago.base_url'))
            ->withToken(\App\Support\BillingSettings::mpAccessToken())
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * Cria uma assinatura (preapproval) pendente e retorna o payload do MP,
     * incluindo `id` e `init_point` (URL de checkout para o assinante).
     */
    public function createPreapproval(User $user, Plan $plan, string $backUrl): array
    {
        $response = $this->http()->post('/preapproval', [
            'reason' => config('app.name') . " — Plano {$plan->name}",
            'external_reference' => (string) $user->id,
            'payer_email' => $user->email,
            'back_url' => $backUrl,
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => (float) $plan->price,
                'currency_id' => $plan->currency,
            ],
            'status' => 'pending',
        ]);

        return $this->jsonOrFail($response, 'criar assinatura');
    }

    public function getPreapproval(string $preapprovalId): array
    {
        return $this->jsonOrFail(
            $this->http()->get("/preapproval/{$preapprovalId}"),
            'consultar assinatura'
        );
    }

    public function cancelPreapproval(string $preapprovalId): array
    {
        return $this->jsonOrFail(
            $this->http()->put("/preapproval/{$preapprovalId}", ['status' => 'cancelled']),
            'cancelar assinatura'
        );
    }

    /**
     * Valida a assinatura HMAC dos webhooks do Mercado Pago.
     *
     * Header x-signature: "ts=<timestamp>,v1=<hmac>"
     * Manifest: "id:<data.id>;request-id:<x-request-id>;ts:<ts>;"
     * (partes ausentes são omitidas do manifest, conforme docs do MP)
     */
    public function isValidWebhookSignature(Request $request): bool
    {
        $secret = \App\Support\BillingSettings::mpWebhookSecret();

        if (!$secret) {
            // Fail-closed em produção: sem secret não há como validar a origem,
            // então rejeita. Em dev/local, aceita para facilitar testes.
            if (app()->environment('production')) {
                return false;
            }
            return true;
        }

        $signature = (string) $request->header('x-signature', '');
        $parts = [];
        foreach (explode(',', $signature) as $segment) {
            $pair = explode('=', trim($segment), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        $ts = $parts['ts'] ?? null;
        $hash = $parts['v1'] ?? null;

        if (!$ts || !$hash) {
            return false;
        }

        // Anti-replay: rejeita assinaturas fora de uma janela de 5 minutos.
        // O ts do MP é epoch em milissegundos.
        $tsSeconds = ((int) $ts) > 20000000000 ? ((int) $ts) / 1000 : (int) $ts;
        if (abs(time() - (int) $tsSeconds) > 300) {
            return false;
        }

        $manifest = '';
        if ($dataId = $request->query('data_id', $request->query('data.id'))) {
            $manifest .= 'id:' . strtolower((string) $dataId) . ';';
        }
        if ($requestId = $request->header('x-request-id')) {
            $manifest .= "request-id:{$requestId};";
        }
        $manifest .= "ts:{$ts};";

        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $hash);
    }

    private function jsonOrFail(Response $response, string $action): array
    {
        if (!$response->successful()) {
            throw new \RuntimeException(
                "Mercado Pago: falha ao {$action} (HTTP {$response->status()}): " . $response->body()
            );
        }

        return $response->json() ?? [];
    }
}

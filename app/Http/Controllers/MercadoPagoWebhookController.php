<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SystemLog;
use App\Services\Billing\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(private MercadoPagoService $mercadoPago) {}

    /**
     * Webhook de assinaturas do Mercado Pago.
     * POST /webhook/mercadopago
     *
     * Eventos relevantes: type=subscription_preapproval (criação/alteração
     * de status da assinatura). A assinatura HMAC (x-signature) é validada
     * quando MERCADOPAGO_WEBHOOK_SECRET está configurado.
     */
    public function handle(Request $request)
    {
        if (!$this->mercadoPago->isValidWebhookSignature($request)) {
            Log::warning('Mercado Pago webhook: assinatura inválida', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'invalid signature'], 401);
        }

        $type = $request->input('type', $request->input('topic'));
        $dataId = $request->input('data.id', $request->query('data_id', $request->input('id')));

        if (!in_array($type, ['subscription_preapproval', 'preapproval'])) {
            // Outros eventos (pagamentos etc.) são aceitos e ignorados por ora
            return response()->json(['status' => 'ignored']);
        }

        if (!$dataId) {
            return response()->json(['status' => 'missing data.id'], 400);
        }

        try {
            $preapproval = $this->mercadoPago->getPreapproval((string) $dataId);
        } catch (\Throwable $e) {
            Log::error("Mercado Pago webhook: erro ao buscar preapproval {$dataId}: {$e->getMessage()}");

            return response()->json(['status' => 'error'], 500);
        }

        $subscription = Subscription::where('mp_preapproval_id', $dataId)->first();

        if (!$subscription && !empty($preapproval['external_reference'])) {
            $subscription = Subscription::where('user_id', (int) $preapproval['external_reference'])
                ->whereNull('mp_preapproval_id')
                ->latest('id')
                ->first();

            $subscription?->update(['mp_preapproval_id' => $dataId]);
        }

        if (!$subscription) {
            Log::warning("Mercado Pago webhook: assinatura local não encontrada para preapproval {$dataId}");

            return response()->json(['status' => 'subscription not found']);
        }

        $newStatus = Subscription::statusFromMercadoPago($preapproval['status'] ?? '');

        if ($newStatus && $newStatus !== $subscription->status) {
            $subscription->update([
                'status' => $newStatus,
                'current_period_end' => isset($preapproval['next_payment_date'])
                    ? \Illuminate\Support\Carbon::parse($preapproval['next_payment_date'])
                    : $subscription->current_period_end,
                'cancelled_at' => $newStatus === Subscription::STATUS_CANCELLED ? now() : null,
            ]);

            // Ao ativar uma assinatura paga, encerra trials/assinaturas antigas do usuário
            if ($newStatus === Subscription::STATUS_ACTIVE) {
                $subscription->user->subscriptions()
                    ->where('id', '!=', $subscription->id)
                    ->whereIn('status', [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING])
                    ->update(['status' => Subscription::STATUS_CANCELLED, 'cancelled_at' => now()]);
            }

            SystemLog::info('billing', 'subscription.status_changed', "Assinatura #{$subscription->id}: {$subscription->getOriginal('status')} → {$newStatus}", [
                'user_id' => $subscription->user_id,
                'mp_preapproval_id' => $dataId,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }
}

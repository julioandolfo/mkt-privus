<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Services\Billing\MercadoPagoService;
use App\Services\Billing\UsageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function __construct(
        private MercadoPagoService $mercadoPago,
        private UsageService $usage,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $subscription = $this->usage->activeSubscription($user)
            ?? $user->subscriptions()->latest('id')->first();

        return Inertia::render('Billing/Index', [
            'billingEnabled' => (bool) config('billing.enabled'),
            'plans' => Plan::active()->get(),
            'subscription' => $subscription?->load('plan'),
            'usage' => $this->usage->summary($user),
        ]);
    }

    /**
     * Cria a assinatura no Mercado Pago e redireciona ao checkout (init_point)
     */
    public function subscribe(Request $request, Plan $plan)
    {
        abort_unless(config('billing.enabled'), 404);
        abort_unless($plan->is_active, 404);

        $user = $request->user();

        try {
            $preapproval = $this->mercadoPago->createPreapproval(
                $user,
                $plan,
                route('billing.index')
            );
        } catch (\Throwable $e) {
            SystemLog::error('billing', 'subscribe.error', "Erro ao criar assinatura MP: {$e->getMessage()}", [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
            ]);

            return back()->with('error', 'Não foi possível iniciar a assinatura. Tente novamente em instantes.');
        }

        $user->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
            'mp_preapproval_id' => $preapproval['id'] ?? null,
        ]);

        $initPoint = $preapproval['init_point'] ?? null;

        if (!$initPoint) {
            return back()->with('error', 'O Mercado Pago não retornou a URL de checkout.');
        }

        // Redirecionamento externo (fora do Inertia)
        return Inertia::location($initPoint);
    }

    /**
     * Cancela a assinatura ativa
     */
    public function cancel(Request $request)
    {
        $user = $request->user();
        $subscription = $this->usage->activeSubscription($user);

        if (!$subscription) {
            return back()->with('error', 'Nenhuma assinatura ativa para cancelar.');
        }

        if ($subscription->mp_preapproval_id) {
            try {
                $this->mercadoPago->cancelPreapproval($subscription->mp_preapproval_id);
            } catch (\Throwable $e) {
                SystemLog::error('billing', 'cancel.error', "Erro ao cancelar assinatura MP: {$e->getMessage()}", [
                    'subscription_id' => $subscription->id,
                ]);

                return back()->with('error', 'Não foi possível cancelar junto ao Mercado Pago. Tente novamente.');
            }
        }

        $subscription->update([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        SystemLog::info('billing', 'subscription.cancelled', "Assinatura #{$subscription->id} cancelada pelo usuário", [
            'user_id' => $user->id,
        ]);

        return back()->with('success', 'Assinatura cancelada.');
    }
}

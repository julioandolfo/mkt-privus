<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BrandRole;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\Billing\MercadoPagoService;
use App\Services\Billing\UsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Back-office do operador do SaaS (rotas protegidas pelo middleware 'admin'):
 * visão e gestão de todas as contas, assinaturas e uso.
 */
class AccountsController extends Controller
{
    public function __construct(
        private UsageService $usage,
        private MercadoPagoService $mercadoPago,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->get('q', ''));

        $accounts = User::query()
            ->with('currentBrand')
            ->when($search, fn ($query) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(function (User $user) {
                $subscription = $this->usage->activeSubscription($user)
                    ?? $user->subscriptions()->latest('id')->first();

                $brand = $user->currentBrand;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => (bool) $user->is_admin,
                    'is_active' => $user->is_active !== false,
                    'created_at' => $user->created_at?->toDateString(),
                    'last_login_at' => $user->last_login_at?->toDateTimeString(),
                    'brands_count' => $this->usage->brandsCount($user),
                    'company' => $brand ? [
                        'name' => $brand->name,
                        'legal_name' => $brand->legal_name,
                        'cnpj' => $brand->cnpj,
                        'segment' => $brand->segment,
                        'company_size' => $brand->company_size,
                        'phone' => $brand->phone,
                        'city' => $brand->address_city,
                        'state' => $brand->address_state,
                        'goals' => $brand->goals ?? [],
                        'objective' => $brand->objective,
                    ] : null,
                    'subscription' => $subscription ? [
                        'id' => $subscription->id,
                        'plan_name' => $subscription->plan?->name,
                        'status' => $subscription->status,
                        'trial_ends_at' => $subscription->trial_ends_at?->toDateString(),
                        'current_period_end' => $subscription->current_period_end?->toDateString(),
                        'is_valid' => $subscription->isValid(),
                    ] : null,
                    'usage' => [
                        'emails' => $this->usage->emailsSentThisMonth($user),
                        'ai_tokens' => $this->usage->aiTokensThisMonth($user),
                    ],
                ];
            });

        $activeSubscriptions = Subscription::where('status', Subscription::STATUS_ACTIVE)->with('plan')->get();

        $stats = [
            'total_accounts' => User::count(),
            'active_subscriptions' => $activeSubscriptions->count(),
            'trialing' => Subscription::where('status', Subscription::STATUS_TRIALING)
                ->where('trial_ends_at', '>', now())
                ->count(),
            'mrr' => round($activeSubscriptions->sum(fn ($s) => (float) ($s->plan?->price ?? 0)), 2),
        ];

        return Inertia::render('Admin/Accounts', [
            'accounts' => $accounts,
            'stats' => $stats,
            'plans' => Plan::active()->get(['id', 'name', 'price']),
            'filters' => ['q' => $search],
        ]);
    }

    /**
     * Métricas de onboarding para o operador: distribuição por segmento,
     * objetivos buscados, porte e UF.
     */
    public function insights(): Response
    {
        $brands = Brand::query()->get([
            'segment', 'company_size', 'address_state', 'goals',
        ]);

        return Inertia::render('Admin/Insights', [
            'totals' => [
                'brands' => $brands->count(),
                'accounts' => User::count(),
                'with_cnpj' => Brand::whereNotNull('cnpj')->where('cnpj', '!=', '')->count(),
            ],
            'bySegment' => $this->distribution($brands, 'segment'),
            'byCompanySize' => $this->distribution($brands, 'company_size'),
            'byState' => $this->distribution($brands, 'address_state'),
            'byGoal' => $this->goalsDistribution($brands),
        ]);
    }

    /** Conta valores de uma coluna, ordenado desc. @return array<int,array{label:string,count:int}> */
    private function distribution($brands, string $field): array
    {
        return $brands
            ->groupBy(fn ($b) => filled($b->{$field}) ? $b->{$field} : 'Não informado')
            ->map(fn ($group, $label) => ['label' => (string) $label, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /** Tabula o array JSON de goals (multi-valor) entre as marcas. */
    private function goalsDistribution($brands): array
    {
        $tally = [];

        foreach ($brands as $brand) {
            foreach (($brand->goals ?? []) as $goal) {
                $tally[$goal] = ($tally[$goal] ?? 0) + 1;
            }
        }

        arsort($tally);

        return collect($tally)->map(fn ($count, $label) => ['label' => $label, 'count' => $count])->values()->all();
    }

    /**
     * Cria uma conta de cliente isolada: usuário (Owner) + marca própria, e
     * opcionalmente já inicia um trial ou concede um plano cortesia.
     *
     * Diferente de Configurações → Usuários (equipe da plataforma), aqui a
     * conta nasce com a SUA própria marca, sem acesso a nenhuma outra.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:users,email',
            'brand_name' => 'required|string|max:255',
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            // none = sem assinatura | trial = teste grátis | plan = cortesia
            'subscription' => 'required|in:none,trial,plan',
            'plan_id' => 'required_if:subscription,plan|nullable|exists:plans,id',
            'trial_days' => 'nullable|integer|min:1|max:90',
        ]);

        $generatedPassword = null;

        $user = DB::transaction(function () use ($validated, $request, &$generatedPassword) {
            $password = $validated['password'] ?? null;
            if (!$password) {
                $password = Str::password(14);
                $generatedPassword = $password;
            }

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($password),
                'is_active' => true,
                'is_admin' => false,
            ]);
            // Conta criada pelo admin nasce verificada
            $user->forceFill(['email_verified_at' => now()])->save();

            $brand = Brand::create([
                'name' => $validated['brand_name'],
                'slug' => $this->uniqueBrandSlug($validated['brand_name']),
                'is_active' => true,
            ]);
            $brand->users()->attach($user->id, ['role' => BrandRole::Owner->value]);
            $user->update(['current_brand_id' => $brand->id]);

            if ($validated['subscription'] === 'trial') {
                $plan = $validated['plan_id'] ? Plan::find($validated['plan_id']) : Plan::active()->first();
                if ($plan) {
                    $user->subscriptions()->create([
                        'plan_id' => $plan->id,
                        'status' => Subscription::STATUS_TRIALING,
                        'trial_ends_at' => now()->addDays(
                            $validated['trial_days'] ?? \App\Support\BillingSettings::trialDays()
                        ),
                    ]);
                }
            } elseif ($validated['subscription'] === 'plan') {
                $user->subscriptions()->create([
                    'plan_id' => $validated['plan_id'],
                    'status' => Subscription::STATUS_ACTIVE,
                    'metadata' => ['granted_by' => $request->user()->id, 'courtesy' => true],
                ]);
            }

            return $user;
        });

        SystemLog::info('admin', 'account.created', "Conta de cliente criada: {$user->email}", [
            'user_id' => $request->user()->id,
            'target_user_id' => $user->id,
        ]);

        $message = "Conta criada para {$user->email}.";
        if ($generatedPassword) {
            $message .= " Senha temporária: {$generatedPassword}";
        }

        return back()->with('success', $message);
    }

    private function uniqueBrandSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'marca';
        $slug = $base;

        while (Brand::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    /**
     * Ativa/desativa o acesso de uma conta (bloqueia o login)
     */
    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Você não pode desativar a própria conta.');
        }

        $user->update(['is_active' => !($user->is_active !== false)]);

        SystemLog::info('admin', 'account.toggle_active', "Conta {$user->email} " . ($user->is_active ? 'ativada' : 'desativada'), [
            'user_id' => $request->user()->id,
            'target_user_id' => $user->id,
        ]);

        return back()->with('success', $user->is_active ? 'Conta ativada.' : 'Conta desativada.');
    }

    /**
     * Promove/rebaixa administrador da plataforma
     */
    public function toggleAdmin(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Você não pode alterar o próprio acesso de administrador.');
        }

        $user->update(['is_admin' => !$user->is_admin]);

        SystemLog::info('admin', 'account.toggle_admin', "Conta {$user->email} " . ($user->is_admin ? 'promovida a admin' : 'rebaixada de admin'), [
            'user_id' => $request->user()->id,
            'target_user_id' => $user->id,
        ]);

        return back()->with('success', $user->is_admin ? 'Usuário promovido a administrador.' : 'Acesso de administrador removido.');
    }

    /**
     * Estende (ou cria) o trial da conta
     */
    public function extendTrial(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate(['days' => 'required|integer|min:1|max:90']);

        $subscription = $user->subscriptions()
            ->whereIn('status', [Subscription::STATUS_TRIALING, Subscription::STATUS_PENDING])
            ->latest('id')
            ->first();

        if ($subscription) {
            $base = $subscription->trial_ends_at?->isFuture() ? $subscription->trial_ends_at : now();
            $subscription->update([
                'status' => Subscription::STATUS_TRIALING,
                'trial_ends_at' => $base->copy()->addDays($validated['days']),
            ]);
        } else {
            $plan = Plan::active()->first();

            if (!$plan) {
                return back()->with('error', 'Nenhum plano ativo disponível para criar o trial.');
            }

            $subscription = $user->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_TRIALING,
                'trial_ends_at' => now()->addDays($validated['days']),
            ]);
        }

        SystemLog::info('admin', 'account.extend_trial', "Trial de {$user->email} estendido em {$validated['days']} dia(s)", [
            'user_id' => $request->user()->id,
            'target_user_id' => $user->id,
            'subscription_id' => $subscription->id,
        ]);

        return back()->with('success', "Trial estendido até {$subscription->trial_ends_at->format('d/m/Y')}.");
    }

    /**
     * Concede um plano cortesia (assinatura ativa sem cobrança no Mercado Pago)
     */
    public function grantPlan(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'months' => 'nullable|integer|min:1|max:36',
        ]);

        // Encerra assinaturas vigentes antes de conceder a cortesia
        $user->subscriptions()
            ->whereIn('status', [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING])
            ->update(['status' => Subscription::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $subscription = $user->subscriptions()->create([
            'plan_id' => $validated['plan_id'],
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => isset($validated['months'])
                ? now()->addMonths((int) $validated['months'])
                : null,
            'metadata' => ['granted_by' => $request->user()->id, 'courtesy' => true],
        ]);

        SystemLog::info('admin', 'account.grant_plan', "Plano cortesia concedido a {$user->email}", [
            'user_id' => $request->user()->id,
            'target_user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $validated['plan_id'],
        ]);

        return back()->with('success', 'Plano concedido à conta.');
    }

    /**
     * Cancela a assinatura vigente da conta (incluindo no Mercado Pago)
     */
    public function cancelSubscription(Request $request, User $user): RedirectResponse
    {
        $subscription = $this->usage->activeSubscription($user);

        if (!$subscription) {
            return back()->with('error', 'Esta conta não possui assinatura vigente.');
        }

        if ($subscription->mp_preapproval_id) {
            try {
                $this->mercadoPago->cancelPreapproval($subscription->mp_preapproval_id);
            } catch (\Throwable $e) {
                SystemLog::error('admin', 'account.cancel_subscription.mp_error', "Erro ao cancelar no MP: {$e->getMessage()}", [
                    'subscription_id' => $subscription->id,
                ]);

                return back()->with('error', 'Falha ao cancelar junto ao Mercado Pago. Tente novamente.');
            }
        }

        $subscription->update([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        SystemLog::info('admin', 'account.cancel_subscription', "Assinatura de {$user->email} cancelada pelo admin", [
            'user_id' => $request->user()->id,
            'target_user_id' => $user->id,
            'subscription_id' => $subscription->id,
        ]);

        return back()->with('success', 'Assinatura cancelada.');
    }
}

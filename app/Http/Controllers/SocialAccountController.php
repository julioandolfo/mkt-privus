<?php

namespace App\Http\Controllers;

use App\Enums\SocialPlatform;
use App\Models\OAuthDiscoveredAccount;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\Setting;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SocialAccountController extends Controller
{
    /**
     * Lista contas sociais conectadas da marca ativa
     */
    public function index(Request $request): Response
    {
        try {
            $brand = $request->user()->getActiveBrand();
            $accounts = [];

            SystemLog::info('social', 'accounts.index', 'Carregando contas sociais', [
                'user_id' => $request->user()->id,
                'brand_id' => $brand?->id,
            ]);

            // O console de conexões opera sobre TODAS as marcas do usuário (não
            // apenas a marca ativa): assim conexões de outra marca do usuário
            // continuam visíveis/gerenciáveis em vez de "sumirem" e darem erro.
            // A visibilidade continua restrita às marcas do usuário (multi-tenant).
            $allAccounts = $this->managedAccountsQuery()
                ->with('brand:id,name')
                ->orderBy('platform')
                ->get();

            SystemLog::info('social', 'accounts.index.count', "Encontradas {$allAccounts->count()} contas", [
                'count' => $allAccounts->count(),
            ]);

            $accounts = $allAccounts->map(function ($acc) {
                    // Ultimo insight disponivel
                    $latestInsight = SocialInsight::where('social_account_id', $acc->id)
                        ->where('sync_status', 'success')
                        ->orderByDesc('date')
                        ->first();

                    // Insight anterior para calcular variacao
                    $previousInsight = null;
                    if ($latestInsight) {
                        $previousInsight = SocialInsight::where('social_account_id', $acc->id)
                            ->where('sync_status', 'success')
                            ->where('date', '<', $latestInsight->date)
                            ->orderByDesc('date')
                            ->first();
                    }

                    $insightData = null;
                    if ($latestInsight) {
                        $insightData = [
                            'date' => $latestInsight->date->format('d/m/Y'),
                            'followers_count' => $latestInsight->followers_count,
                            'following_count' => $latestInsight->following_count,
                            'posts_count' => $latestInsight->posts_count,
                            'impressions' => $latestInsight->impressions,
                            'reach' => $latestInsight->reach,
                            'engagement' => $latestInsight->engagement,
                            'engagement_rate' => $latestInsight->engagement_rate,
                            'likes' => $latestInsight->likes,
                            'comments' => $latestInsight->comments,
                            'shares' => $latestInsight->shares,
                            'saves' => $latestInsight->saves,
                            'clicks' => $latestInsight->clicks,
                            'video_views' => $latestInsight->video_views,
                            'net_followers' => $latestInsight->net_followers,
                            'audience_gender' => $latestInsight->audience_gender,
                            'audience_age' => $latestInsight->audience_age,
                            'audience_cities' => $latestInsight->audience_cities,
                            'audience_countries' => $latestInsight->audience_countries,
                            'platform_data' => $latestInsight->platform_data,
                            'followers_variation' => $previousInsight && $previousInsight->followers_count > 0
                                ? round((($latestInsight->followers_count - $previousInsight->followers_count) / $previousInsight->followers_count) * 100, 1)
                                : null,
                        ];
                    }

                    return [
                        'id' => $acc->id,
                        'platform' => $acc->platform->value,
                        'platform_label' => $acc->platform->label(),
                        'platform_color' => $acc->platform->color(),
                        'source' => $acc->source ?? 'direct',
                        'username' => $acc->username,
                        'display_name' => $acc->display_name,
                        'avatar_url' => $acc->avatar_url,
                        'is_active' => $acc->is_active,
                        'token_status' => $this->getTokenStatus($acc),
                        'metadata' => $acc->metadata,
                        'created_at' => $acc->created_at->format('d/m/Y'),
                        'insights' => $insightData,
                        'brand_id' => $acc->brand_id,
                        'brand_name' => $acc->brand?->name,
                    ];
                });

            $platforms = collect(SocialPlatform::cases())->map(fn($p) => [
            'value' => $p->value,
            'label' => $p->label(),
            'color' => $p->color(),
        ])->toArray();

        // Verificar credenciais OAuth configuradas (plataformas diretas + Postiz)
        // Postiz: aceita tanto Setting (definido pela UI) quanto .env.
        $postizConfigured = !empty(Setting::get('oauth', 'postiz_api_key'))
            || !empty(config('services.postiz.api_key'));
        $oauthConfigured = [
            'facebook' => $this->hasOAuthConfig('meta'),
            'instagram' => $this->hasOAuthConfig('meta'),
            'youtube' => $this->hasOAuthConfig('google'),
            'linkedin' => $postizConfigured,
            'linkedin_page' => $postizConfigured,
            'tiktok' => $postizConfigured,
            'google_my_business' => $postizConfigured,
        ];

        // Contas descobertas via OAuth - do BANCO DE DADOS (nao mais sessao)
        $discoveredAccounts = [];
        $oauthPlatform = null;
        $discoveryToken = null;

        // Verificar se veio token via query string
        $tokenFromQuery = $request->get('discovery_token');

        if ($tokenFromQuery) {
            $discovery = OAuthDiscoveredAccount::where('session_token', $tokenFromQuery)
                ->where('user_id', $request->user()->id)
                ->where('expires_at', '>', now())
                ->first();
        } else {
            // Fallback: buscar o mais recente do usuario nao expirado
            $discovery = OAuthDiscoveredAccount::where('user_id', $request->user()->id)
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->first();
        }

        if ($discovery) {
            $discoveredAccounts = $discovery->accounts;
            $oauthPlatform = $discovery->platform;
            $discoveryToken = $discovery->session_token;
        }

        // Apenas as marcas do usuário podem ser destino de vínculo (multi-tenant).
        $brands = $request->user()->brands()->orderBy('name')->get(['brands.id', 'brands.name']);

        SystemLog::info('social', 'accounts.index.render', 'Renderizando pagina', [
            'accounts_count' => count($accounts),
            'brands_count' => $brands->count(),
        ]);

        return Inertia::render('Social/Accounts/Index', [
            'accounts' => $accounts,
            'platforms' => $platforms,
            'oauthConfigured' => $oauthConfigured,
            'discoveredAccounts' => $discoveredAccounts,
            'oauthPlatform' => $oauthPlatform,
            'discoveryToken' => $discoveryToken,
            'brands' => $brands,
        ]);

        } catch (\Throwable $e) {
            SystemLog::error('social', 'accounts.index.error', "Erro ao carregar contas: {$e->getMessage()}", [
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 1500),
            ]);
            Log::error('Social accounts index error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Adicionar conta social manualmente (com tokens da API)
     */
    public function store(Request $request): RedirectResponse
    {
        $brand = $request->user()->getActiveBrand();

        $validated = $request->validate([
            'platform' => 'required|string',
            'username' => 'required|string|max:255',
            'display_name' => 'nullable|string|max:255',
            'platform_user_id' => 'nullable|string|max:255',
            'access_token' => 'nullable|string|max:2000',
            'refresh_token' => 'nullable|string|max:2000',
            'token_expires_at' => 'nullable|date',
        ]);

        // Verificar se a conta ja existe para esta plataforma/username em
        // QUALQUER brand. Sem withoutGlobalScope('brand'), o exists() só
        // enxergaria a brand ativa do usuário e permitiria criar duplicatas
        // (que depois quebram o linkBrand com violação de unique constraint).
        $exists = SocialAccount::withoutGlobalScope('brand')
            ->where('platform', $validated['platform'])
            ->where('username', $validated['username'])
            ->exists();

        if ($exists) {
            return redirect()->back()->withErrors([
                'username' => 'Esta conta já está conectada nesta plataforma (em alguma marca). Use a tela de Contas para vinculá-la a esta marca.',
            ]);
        }

        SocialAccount::create([
            'brand_id' => $brand?->id,
            'platform' => $validated['platform'],
            'username' => $validated['username'],
            'display_name' => $validated['display_name'] ?? $validated['username'],
            'platform_user_id' => $validated['platform_user_id'] ?? null,
            'access_token' => $validated['access_token'] ?? null,
            'refresh_token' => $validated['refresh_token'] ?? null,
            'token_expires_at' => $validated['token_expires_at'] ?? null,
            'is_active' => true,
        ]);

        return redirect()->route('social.accounts.index')
            ->with('success', 'Conta conectada com sucesso!');
    }

    /**
     * Atualizar conta social (tokens, status)
     */
    public function update(Request $request, string $account): RedirectResponse
    {
        $account = $this->resolveManagedAccount($account);

        $validated = $request->validate([
            'display_name' => 'nullable|string|max:255',
            'access_token' => 'nullable|string|max:2000',
            'refresh_token' => 'nullable|string|max:2000',
            'token_expires_at' => 'nullable|date',
            'is_active' => 'sometimes|boolean',
        ]);

        $account->update($validated);

        return redirect()->back()->with('success', 'Conta atualizada com sucesso!');
    }

    /**
     * Desconectar conta social
     */
    public function destroy(Request $request, string $account): RedirectResponse
    {
        $account = $this->resolveManagedAccount($account);

        $account->delete();

        return redirect()->route('social.accounts.index')
            ->with('success', 'Conta desconectada com sucesso!');
    }

    /**
     * Toggle ativo/inativo
     */
    public function toggle(Request $request, string $account): RedirectResponse
    {
        $account = $this->resolveManagedAccount($account);

        $account->update(['is_active' => !$account->is_active]);

        $status = $account->is_active ? 'ativada' : 'desativada';

        return redirect()->back()->with('success', "Conta {$status} com sucesso!");
    }

    /**
     * Vincular/desvincular conta social a uma marca
     */
    public function linkBrand(Request $request, string $account): JsonResponse
    {
        // Resolvido fora do try (404 limpo se fora do alcance) e considerando
        // TODAS as marcas do usuário, não só a ativa. Antes, uma conta atribuída
        // a outra marca do usuário não era encontrada → "Erro ao vincular marca".
        $account = $this->resolveManagedAccount($account);

        try {
            $brandId = $request->input('brand_id');

            SystemLog::info('social', 'account.link_brand.start', "Vinculando conta #{$account->id} a marca", [
                'account_id' => $account->id,
                'account_username' => $account->username,
                'brand_id_received' => $brandId,
                'brand_id_type' => gettype($brandId),
                'current_brand_id' => $account->brand_id,
            ]);

            if ($brandId) {
                // 1) Permissão (multi-tenant): só permite vincular a uma marca
                //    da qual o usuário é membro. Retorna 404 antes de qualquer
                //    outra checagem.
                $brand = $request->user()->brands()->where('brands.id', $brandId)->first();

                if (!$brand) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Marca não encontrada ou sem acesso.',
                    ], 404);
                }

                // 2) Anti-duplicação: o banco tem unique constraint
                //    social_accounts_brand_platform_uid_unique (sufixo "uid" por
                //    compatibilidade histórica; a coluna real é
                //    platform_user_id). Sem este SELECT, o UPDATE explode com
                //    SQLSTATE 23000 e o front mostra um erro genérico
                //    incompreensível. Acontece quando a mesma conta foi
                //    cadastrada 2x e o usuário tenta mover uma delas pra brand
                //    que já tem a outra.
                $conflict = SocialAccount::where('brand_id', $brand->id)
                    ->where('platform', $account->platform)
                    ->where('platform_user_id', $account->platform_user_id)
                    ->where('id', '!=', $account->id)
                    ->first();

                if ($conflict) {
                    SystemLog::warning('social', 'account.link_brand.duplicate', "Tentativa de vincular conta duplicada", [
                        'account_id' => $account->id,
                        'conflict_account_id' => $conflict->id,
                        'target_brand_id' => $brand->id,
                        'platform_user_id' => $account->platform_user_id,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => "A marca \"{$brand->name}\" já tem uma conta @{$conflict->username} cadastrada (#{$conflict->id}). Remova ou desvincule a duplicada antes de mudar esta.",
                    ], 409);
                }

                $account->update(['brand_id' => $brand->id]);

                SystemLog::info('social', 'account.link_brand.linked', "Conta vinculada a \"{$brand->name}\"", [
                    'account_id' => $account->id,
                    'brand_id' => $brand->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => "Conta vinculada a \"{$brand->name}\".",
                    'brand_id' => $brand->id,
                    'brand_name' => $brand->name,
                ]);
            }

            $account->update(['brand_id' => null]);

            SystemLog::info('social', 'account.link_brand.unlinked', "Conta desvinculada (global)", [
                'account_id' => $account->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Conta desvinculada (global).',
                'brand_id' => null,
                'brand_name' => null,
            ]);
        } catch (\Throwable $e) {
            SystemLog::error('social', 'account.link_brand.error', "Erro ao vincular marca: {$e->getMessage()}", [
                'account_id' => $account->id,
                'brand_id' => $request->input('brand_id'),
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao vincular: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sincronizar insights de uma conta social manualmente
     */
    public function syncAccount(Request $request, string $account): JsonResponse
    {
        $account = $this->resolveManagedAccount($account);

        try {
            if (!$account->access_token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta conta não possui token de acesso. Reconecte via OAuth.',
                ]);
            }

            // Tentar renovar token se necessário antes de sincronizar
            if ($account->isTokenExpired() || $account->needsRefresh()) {
                if (!$account->ensureFreshToken()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Token expirado e não foi possível renovar. Reconecte a conta.',
                    ]);
                }
            }

            $service = app(\App\Services\Social\SocialInsightsService::class);
            $result = $service->syncAccount($account);

            if ($result) {
                // Recarregar insight mais recente para retornar dados atualizados
                $latestInsight = SocialInsight::where('social_account_id', $account->id)
                    ->where('sync_status', 'success')
                    ->orderByDesc('date')
                    ->first();

                return response()->json([
                    'success' => true,
                    'message' => "Insights de @{$account->username} sincronizados com sucesso!",
                    'followers_count' => $latestInsight?->followers_count,
                    'reach' => $latestInsight?->reach,
                    'engagement' => $latestInsight?->engagement,
                    'engagement_rate' => $latestInsight?->engagement_rate,
                    'likes' => $latestInsight?->likes,
                    'comments' => $latestInsight?->comments,
                    'platform_data' => $latestInsight?->platform_data,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Não foi possível sincronizar. Verifique os logs para detalhes.',
            ]);
        } catch (\Throwable $e) {
            SystemLog::error('social', 'account.sync.error', "Erro ao sincronizar conta #{$account->id}: {$e->getMessage()}", [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao sincronizar: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Diagnóstico da API de insights do Instagram — testa todas as chamadas e retorna os resultados raw.
     */
    public function diagnoseInsights(string $account): JsonResponse
    {
        $account = $this->resolveManagedAccount($account);

        if (!$account->access_token) {
            return response()->json(['error' => 'Conta sem token de acesso.']);
        }

        $token = $account->getFreshToken() ?? $account->access_token;
        $igUserId = $account->platform_user_id;
        $apiVersion = config('social_oauth.meta.api_version', 'v21.0');

        $results = [
            'account' => [
                'id' => $account->id,
                'username' => $account->username,
                'platform_user_id' => $igUserId,
                'platform' => $account->platform->value ?? $account->platform,
                'token_status' => $this->getTokenStatus($account),
                'token_expires_at' => $account->token_expires_at?->toDateTimeString(),
            ],
            'api_version' => $apiVersion,
        ];

        // Teste 0: Profile data (deve funcionar se token valido)
        $profile = \Illuminate\Support\Facades\Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}", [
            'access_token' => $token,
            'fields' => 'id,name,username,followers_count,follows_count,media_count,biography,profile_picture_url',
        ]);
        $results['test0_profile'] = [
            'status' => $profile->status(),
            'data' => $profile->json(),
        ];

        // Teste 1: Insights com metric_type=total_value (API v18+)
        $since28 = now()->subDays(28)->startOfDay()->timestamp;
        $untilNow = now()->endOfDay()->timestamp;

        $t1 = \Illuminate\Support\Facades\Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/insights", [
            'access_token' => $token,
            'metric' => 'reach,views,total_interactions,accounts_engaged',
            'period' => 'day',
            'metric_type' => 'total_value',
            'since' => $since28,
            'until' => $untilNow,
        ]);
        $results['test1_total_value'] = [
            'status' => $t1->status(),
            'body' => $t1->json() ?? $t1->body(),
        ];

        // Teste 2: Insights period=day sem metric_type
        $t2 = \Illuminate\Support\Facades\Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/insights", [
            'access_token' => $token,
            'metric' => 'reach,views',
            'period' => 'day',
            'since' => now()->subDays(2)->startOfDay()->timestamp,
            'until' => now()->addDay()->startOfDay()->timestamp,
        ]);
        $results['test2_day'] = [
            'status' => $t2->status(),
            'body' => $t2->json() ?? $t2->body(),
        ];

        // Teste 3: period=days_28
        $t3 = \Illuminate\Support\Facades\Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/insights", [
            'access_token' => $token,
            'metric' => 'reach,views',
            'period' => 'days_28',
        ]);
        $results['test3_days28'] = [
            'status' => $t3->status(),
            'body' => $t3->json() ?? $t3->body(),
        ];

        // Teste 4: follower_count (metrica simples — se esta falha, é permissão)
        $t4 = \Illuminate\Support\Facades\Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/insights", [
            'access_token' => $token,
            'metric' => 'follower_count',
            'period' => 'day',
            'since' => now()->subDays(2)->startOfDay()->timestamp,
            'until' => now()->addDay()->startOfDay()->timestamp,
        ]);
        $results['test4_follower_count'] = [
            'status' => $t4->status(),
            'body' => $t4->json() ?? $t4->body(),
        ];

        // Teste 5: Debug token permissions
        $t5 = \Illuminate\Support\Facades\Http::get("https://graph.facebook.com/debug_token", [
            'input_token' => $token,
            'access_token' => $token,
        ]);
        $results['test5_debug_token'] = [
            'status' => $t5->status(),
            'scopes' => $t5->json('data.scopes') ?? [],
            'type' => $t5->json('data.type') ?? null,
            'app_id' => $t5->json('data.app_id') ?? null,
            'is_valid' => $t5->json('data.is_valid') ?? null,
            'expires_at' => $t5->json('data.expires_at') ?? null,
            'error' => $t5->json('data.error') ?? null,
        ];

        return response()->json($results, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    // ===== PRIVATE =====

    /**
     * Query base de conexões geríveis pelo usuário atual.
     *
     * Substitui o escopo de marca ATIVA (que via route-model binding fazia uma
     * conta de outra marca do usuário retornar 404 → "Erro ao vincular marca")
     * por uma visibilidade que abrange TODAS as marcas do usuário, mantendo o
     * isolamento multi-tenant: contas de marcas das quais ele não é membro
     * permanecem inacessíveis. Contas globais (brand_id NULL) seguem a mesma
     * regra de propriedade do escopo padrão do modelo (próprias ou legadas).
     */
    private function managedAccountsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $user = request()->user();
        $brandIds = $user->brands()->pluck('brands.id')->all();

        return SocialAccount::withoutGlobalScope('brand')
            ->where(function ($query) use ($brandIds, $user) {
                $query->whereIn('brand_id', $brandIds)
                    ->orWhere(function ($orphans) use ($user) {
                        $orphans->whereNull('brand_id')
                            ->where(function ($owner) use ($user) {
                                $owner->whereNull('user_id')
                                    ->orWhere('user_id', $user->id);
                            });
                    });
            });
    }

    /**
     * Resolve uma conta gerível pelo usuário (todas as suas marcas + globais),
     * retornando 404 quando estiver fora do seu alcance (isolamento preservado).
     */
    private function resolveManagedAccount(string|int $id): SocialAccount
    {
        return $this->managedAccountsQuery()->findOrFail($id);
    }

    private function getTokenStatus(SocialAccount $account): string
    {
        if (!$account->access_token) {
            return 'sem_token';
        }

        // Se token expirado ou prestes a expirar, tentar renovar automaticamente.
        // canAutoRefresh() cobre Meta (sem refresh_token) e Google (com refresh_token).
        if ($account->isTokenExpired() || $account->needsRefresh()) {
            if ($account->canAutoRefresh() && $account->ensureFreshToken()) {
                return 'ativo'; // Renovado com sucesso
            }

            return $account->isTokenExpired() ? 'expirado' : 'renovar';
        }

        return 'ativo';
    }

    private function hasOAuthConfig(string $provider): bool
    {
        $configKeys = match ($provider) {
            'meta' => ['social_oauth.meta.app_id', 'meta_app_id'],
            'google' => ['social_oauth.google.client_id', 'google_client_id'],
            default => [],
        };

        // Verificar .env / config
        if (!empty(config($configKeys[0] ?? ''))) {
            return true;
        }

        // Verificar settings do banco
        if (isset($configKeys[1])) {
            try {
                $val = Setting::get('oauth', $configKeys[1]);
                return !empty($val);
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
    }
}

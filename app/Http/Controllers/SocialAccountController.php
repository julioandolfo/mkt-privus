<?php

namespace App\Http\Controllers;

use App\Enums\SocialPlatform;
use App\Models\Brand;
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

            $allAccounts = SocialAccount::with('brand:id,name')
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

        // Verificar credenciais OAuth configuradas
        $oauthConfigured = [
            'facebook' => $this->hasOAuthConfig('meta'),
            'instagram' => $this->hasOAuthConfig('meta'),
            'linkedin' => $this->hasOAuthConfig('linkedin'),
            'youtube' => $this->hasOAuthConfig('google'),
            'tiktok' => $this->hasOAuthConfig('tiktok'),
            'pinterest' => $this->hasOAuthConfig('pinterest'),
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

        $brands = Brand::orderBy('name')->get(['id', 'name']);

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

        // Verificar se a conta ja existe para esta plataforma/username
        $exists = SocialAccount::where('platform', $validated['platform'])
            ->where('username', $validated['username'])
            ->exists();

        if ($exists) {
            return redirect()->back()->withErrors([
                'username' => 'Esta conta já está conectada nesta plataforma.',
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
    public function update(Request $request, SocialAccount $account): RedirectResponse
    {
        $this->authorizeAccount($request, $account);

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
    public function destroy(Request $request, SocialAccount $account): RedirectResponse
    {
        $this->authorizeAccount($request, $account);

        $account->delete();

        return redirect()->route('social.accounts.index')
            ->with('success', 'Conta desconectada com sucesso!');
    }

    /**
     * Toggle ativo/inativo
     */
    public function toggle(Request $request, SocialAccount $account): RedirectResponse
    {
        $this->authorizeAccount($request, $account);

        $account->update(['is_active' => !$account->is_active]);

        $status = $account->is_active ? 'ativada' : 'desativada';

        return redirect()->back()->with('success', "Conta {$status} com sucesso!");
    }

    /**
     * Vincular/desvincular conta social a uma marca
     */
    public function linkBrand(Request $request, SocialAccount $account): JsonResponse
    {
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
                $brand = Brand::findOrFail($brandId);
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
    public function syncAccount(Request $request, SocialAccount $account): JsonResponse
    {
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

    // ===== PRIVATE =====

    private function authorizeAccount(Request $request, SocialAccount $account): void
    {
        // Contas globais (brand_id = null) sao acessiveis por qualquer usuario autenticado
        if ($account->brand_id === null) {
            return;
        }

        $brand = $request->user()->getActiveBrand();
        if (!$brand || $account->brand_id !== $brand->id) {
            abort(403, 'Acesso negado.');
        }
    }

    private function getTokenStatus(SocialAccount $account): string
    {
        if (!$account->access_token) {
            return 'sem_token';
        }

        // Se token expirado ou prestes a expirar, tentar renovar automaticamente
        if ($account->isTokenExpired() || $account->needsRefresh()) {
            if ($account->refresh_token && $account->ensureFreshToken()) {
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
            'linkedin' => ['social_oauth.linkedin.client_id', 'linkedin_client_id'],
            'google' => ['social_oauth.google.client_id', 'google_client_id'],
            'tiktok' => ['social_oauth.tiktok.client_key', 'tiktok_client_key'],
            'pinterest' => ['social_oauth.pinterest.app_id', 'pinterest_app_id'],
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

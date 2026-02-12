<?php

namespace App\Http\Controllers;

use App\Enums\SocialPlatform;
use App\Models\Brand;
use App\Models\OAuthDiscoveredAccount;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SocialAccountController extends Controller
{
    /**
     * Lista contas sociais conectadas da marca ativa
     */
    public function index(Request $request): Response
    {
        $brand = $request->user()->getActiveBrand();
        $accounts = [];

        {
            $accounts = SocialAccount::with('brand:id,name')
                ->orderBy('platform')
                ->get()
                ->map(function ($acc) {
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
        }

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

        return Inertia::render('Social/Accounts/Index', [
            'accounts' => $accounts,
            'platforms' => $platforms,
            'oauthConfigured' => $oauthConfigured,
            'discoveredAccounts' => $discoveredAccounts,
            'oauthPlatform' => $oauthPlatform,
            'discoveryToken' => $discoveryToken,
            'brands' => $brands,
        ]);
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
        $brandId = $request->input('brand_id');

        if ($brandId) {
            $brand = Brand::findOrFail($brandId);
            $account->update(['brand_id' => $brand->id]);

            return response()->json([
                'success' => true,
                'message' => "Conta vinculada a \"{$brand->name}\".",
                'brand_id' => $brand->id,
                'brand_name' => $brand->name,
            ]);
        }

        $account->update(['brand_id' => null]);

        return response()->json([
            'success' => true,
            'message' => 'Conta desvinculada (global).',
            'brand_id' => null,
            'brand_name' => null,
        ]);
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

        if ($account->isTokenExpired()) {
            return 'expirado';
        }

        if ($account->needsRefresh()) {
            return 'renovar';
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

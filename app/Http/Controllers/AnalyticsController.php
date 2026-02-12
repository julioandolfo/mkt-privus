<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsConnection;
use App\Models\AnalyticsDataPoint;
use App\Models\AnalyticsDailySummary;
use App\Models\Brand;
use App\Models\ManualAdEntry;
use App\Models\OAuthDiscoveredAccount;
use App\Models\SystemLog;
use App\Services\Analytics\AnalyticsSyncService;
use App\Services\Analytics\GoogleAdsService;
use App\Services\Analytics\GoogleAnalyticsService;
use App\Services\Analytics\GoogleSearchConsoleService;
use App\Services\Analytics\MetaAdsService;
use App\Services\Analytics\WooCommerceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    protected AnalyticsSyncService $syncService;

    public function __construct(AnalyticsSyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Dashboard principal de Analytics
     */
    public function index(Request $request)
    {
        $brandId = $request->get('brand_id') ?? session('active_brand_id');
        $brand = $brandId ? Brand::find($brandId) : Brand::first();

        if (!$brand) {
            return Inertia::render('Analytics/Index', [
                'hasBrand' => false,
                'dashboardData' => null,
                'connections' => [],
            ]);
        }

        // Período de datas
        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        $startDate = $request->get('start_date', now()->subDays(29)->format('Y-m-d'));
        $preset = $request->get('preset', '30d');

        // Período de comparação
        $days = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        $compareEndDate = Carbon::parse($startDate)->subDay()->format('Y-m-d');
        $compareStartDate = Carbon::parse($compareEndDate)->subDays($days - 1)->format('Y-m-d');

        if ($request->get('compare') === 'false') {
            $compareStartDate = null;
            $compareEndDate = null;
        }

        $dashboardData = $this->syncService->getDashboardData(
            $brand->id, $startDate, $endDate, $compareStartDate, $compareEndDate
        );

        $connections = AnalyticsConnection::where('brand_id', $brand->id)
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'platform' => $c->platform,
                'platform_label' => AnalyticsConnection::platformLabels()[$c->platform] ?? $c->platform,
                'platform_color' => AnalyticsConnection::platformColors()[$c->platform] ?? '#6B7280',
                'name' => $c->name,
                'external_name' => $c->external_name,
                'external_id' => $c->external_id,
                'is_active' => $c->is_active,
                'sync_status' => $c->sync_status,
                'sync_error' => $c->sync_error,
                'last_synced_at' => $c->last_synced_at?->diffForHumans(),
                'last_synced_at_raw' => $c->last_synced_at?->toDateTimeString(),
                'created_at' => $c->created_at->format('d/m/Y'),
            ]);

        $brands = Brand::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Analytics/Index', [
            'hasBrand' => true,
            'brand' => $brand,
            'brands' => $brands,
            'dashboardData' => $dashboardData,
            'connections' => $connections,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'preset' => $preset,
                'compare' => $compareStartDate ? true : false,
            ],
        ]);
    }

    /**
     * Página de conexões/integrações
     */
    public function connections(Request $request)
    {
        $brandId = $request->get('brand_id') ?? session('active_brand_id');
        $brand = $brandId ? Brand::find($brandId) : Brand::first();

        $connections = $brand ? AnalyticsConnection::where('brand_id', $brand->id)
            ->with('user:id,name')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'platform' => $c->platform,
                'platform_label' => AnalyticsConnection::platformLabels()[$c->platform] ?? $c->platform,
                'platform_color' => AnalyticsConnection::platformColors()[$c->platform] ?? '#6B7280',
                'name' => $c->name,
                'external_name' => $c->external_name,
                'external_id' => $c->external_id,
                'is_active' => $c->is_active,
                'sync_status' => $c->sync_status,
                'sync_error' => $c->sync_error,
                'last_synced_at' => $c->last_synced_at?->diffForHumans(),
                'created_at' => $c->created_at->format('d/m/Y H:i'),
                'user_name' => $c->user?->name ?? 'Sistema',
                'config' => $c->config,
            ]) : collect();

        // Verificar quais plataformas já têm OAuth configurado
        $oauthConfigured = $this->checkOAuthConfig();

        // Contas descobertas via OAuth (do banco de dados)
        $discoveredAccounts = [];
        $discoveredPlatform = null;
        $discoveryToken = $request->get('discovery_token');

        if ($discoveryToken && auth()->check()) {
            $discovery = OAuthDiscoveredAccount::where('session_token', $discoveryToken)
                ->where('user_id', auth()->id())
                ->where('expires_at', '>', now())
                ->first();

            if ($discovery) {
                $discoveredAccounts = $discovery->accounts;
                $discoveredPlatform = $discovery->platform;
            }
        } elseif (auth()->check()) {
            // Fallback: buscar o mais recente do usuario para analytics
            $discovery = OAuthDiscoveredAccount::where('user_id', auth()->id())
                ->whereIn('platform', ['google_analytics', 'google_ads', 'google_search_console', 'meta_ads'])
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->first();

            if ($discovery) {
                $discoveredAccounts = $discovery->accounts;
                $discoveredPlatform = $discovery->platform;
                $discoveryToken = $discovery->session_token;
            }
        }

        $brands = Brand::orderBy('name')->get(['id', 'name']);

        // Investimentos manuais
        $manualEntries = $brand ? ManualAdEntry::where('brand_id', $brand->id)
            ->with('user:id,name')
            ->orderByDesc('date_start')
            ->get()
            ->map(fn($e) => [
                'id' => $e->id,
                'platform' => $e->platform,
                'platform_label' => $e->platformDisplayName(),
                'platform_custom' => $e->platform_label,
                'date_start' => $e->date_start->format('Y-m-d'),
                'date_end' => $e->date_end->format('Y-m-d'),
                'date_start_display' => $e->date_start->format('d/m/Y'),
                'date_end_display' => $e->date_end->format('d/m/Y'),
                'amount' => (float) $e->amount,
                'daily_amount' => round($e->dailyAmount(), 2),
                'period_days' => $e->periodDays(),
                'description' => $e->description,
                'user_name' => $e->user?->name ?? 'Sistema',
                'created_at' => $e->created_at->format('d/m/Y H:i'),
            ]) : collect();

        return Inertia::render('Analytics/Connections', [
            'brand' => $brand,
            'brands' => $brands,
            'connections' => $connections,
            'oauthConfigured' => $oauthConfigured,
            'discoveredAccounts' => $discoveredAccounts,
            'discoveredPlatform' => $discoveredPlatform,
            'discoveryToken' => $discoveryToken,
            'platforms' => AnalyticsConnection::platformLabels(),
            'platformColors' => AnalyticsConnection::platformColors(),
            'manualEntries' => $manualEntries,
            'manualPlatforms' => ManualAdEntry::platformOptions(),
        ]);
    }

    /**
     * Redireciona para OAuth da plataforma de analytics
     * Suporta modo popup (retorna JSON com URL) e redirect padrão
     */
    public function oauthRedirect(Request $request, string $platform)
    {
        $request->validate(['brand_id' => 'required|exists:brands,id']);

        $state = Str::random(40);

        // Armazenar state no Cache (mais confiavel que sessao em Docker)
        $cacheKey = 'analytics_oauth_' . $state;
        Cache::put($cacheKey, [
            'platform' => $platform,
            'brand_id' => $request->brand_id,
            'user_id' => auth()->id(),
            'popup' => $request->boolean('popup', false),
        ], now()->addMinutes(15));

        // Tambem salvar na sessao como fallback
        session([
            'analytics_oauth_state' => $state,
            'analytics_oauth_platform' => $platform,
            'analytics_oauth_brand_id' => $request->brand_id,
            'analytics_oauth_popup' => $request->boolean('popup', false),
        ]);

        $redirectUri = url('/analytics/oauth/callback/' . $platform);

        SystemLog::info('analytics', 'oauth.redirect', "Iniciando OAuth para {$platform}", [
            'platform' => $platform,
            'brand_id' => $request->brand_id,
            'redirect_uri' => $redirectUri,
            'state' => substr($state, 0, 10) . '...',
            'popup' => $request->boolean('popup', false),
        ]);

        try {
            $url = match ($platform) {
                'google_analytics' => app(GoogleAnalyticsService::class)->getAuthorizationUrl($redirectUri, $state),
                'google_ads' => app(GoogleAdsService::class)->getAuthorizationUrl($redirectUri, $state),
                'google_search_console' => app(GoogleSearchConsoleService::class)->getAuthorizationUrl($redirectUri, $state),
                'meta_ads' => app(MetaAdsService::class)->getAuthorizationUrl($redirectUri, $state),
                default => throw new \InvalidArgumentException("Plataforma '{$platform}' não suportada"),
            };
        } catch (\Throwable $e) {
            SystemLog::error('analytics', 'oauth.redirect.error', "Erro ao gerar URL OAuth: {$e->getMessage()}", [
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        SystemLog::debug('analytics', 'oauth.redirect.url', "URL OAuth gerada com sucesso", [
            'platform' => $platform,
            'url_preview' => substr($url, 0, 100) . '...',
        ]);

        // Se for popup, retorna JSON com a URL para o frontend abrir em nova janela
        if ($request->boolean('popup')) {
            return response()->json(['url' => $url]);
        }

        return Inertia::location($url);
    }

    /**
     * Callback do OAuth
     * Suporta modo popup (retorna blade que fecha popup) e redirect padrão
     */
    public function oauthCallback(Request $request, string $platform)
    {
        $requestState = $request->get('state');
        $code = $request->get('code');
        $error = $request->get('error');

        SystemLog::info('analytics', 'oauth.callback', "Callback OAuth recebido para {$platform}", [
            'platform' => $platform,
            'has_code' => !empty($code),
            'has_error' => !empty($error),
            'error_description' => $request->get('error_description'),
            'state_received' => $requestState ? substr($requestState, 0, 10) . '...' : 'VAZIO',
            'query_params' => array_keys($request->query()),
        ]);

        // Tentar recuperar dados do state via Cache (prioridade) ou Sessao (fallback)
        $oauthData = null;

        if ($requestState) {
            $cacheKey = 'analytics_oauth_' . $requestState;
            $oauthData = Cache::get($cacheKey);

            if ($oauthData) {
                SystemLog::debug('analytics', 'oauth.callback.cache', "Dados OAuth recuperados do Cache", [
                    'platform' => $oauthData['platform'] ?? 'n/a',
                    'brand_id' => $oauthData['brand_id'] ?? 'n/a',
                ]);
            }
        }

        // Fallback para sessao
        if (!$oauthData) {
            $sessionState = session('analytics_oauth_state');
            $brandId = session('analytics_oauth_brand_id');
            $isPopup = session('analytics_oauth_popup', false);

            SystemLog::warning('analytics', 'oauth.callback.session_fallback', "Cache miss, usando sessao como fallback", [
                'session_state' => $sessionState ? substr($sessionState, 0, 10) . '...' : 'VAZIO',
                'brand_id' => $brandId,
                'states_match' => $requestState === $sessionState,
            ]);

            if ($requestState && $requestState === $sessionState) {
                $oauthData = [
                    'platform' => $platform,
                    'brand_id' => $brandId,
                    'user_id' => auth()->id(),
                    'popup' => $isPopup,
                ];
            }
        }

        // Se nao encontrou dados, mas tem usuario logado, tentar sem validacao de state
        // (cenario de sessao perdida em Docker)
        if (!$oauthData && auth()->check()) {
            $brandId = session('analytics_oauth_brand_id') ?? auth()->user()->current_brand_id;
            $isPopup = session('analytics_oauth_popup', false);

            SystemLog::warning('analytics', 'oauth.callback.no_state', "State nao validado - usando brand do usuario", [
                'brand_id' => $brandId,
                'user_id' => auth()->id(),
            ]);

            $oauthData = [
                'platform' => $platform,
                'brand_id' => $brandId,
                'user_id' => auth()->id(),
                'popup' => $isPopup,
            ];
        }

        $brandId = $oauthData['brand_id'] ?? null;
        $isPopup = $oauthData['popup'] ?? false;

        // Verificar erro do provider
        if ($error) {
            $errorMsg = $request->get('error_description', $error);
            SystemLog::error('analytics', 'oauth.callback.provider_error', "Erro do provider OAuth: {$errorMsg}", [
                'platform' => $platform,
                'error' => $error,
                'error_description' => $errorMsg,
            ]);

            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'error',
                    'message' => "Erro do Google: {$errorMsg}",
                    'platform' => $platform,
                    'accountsCount' => 0,
                    'brandId' => $brandId,
                ]);
            }
            return redirect()->route('analytics.connections')
                ->with('error', "Erro do Google: {$errorMsg}");
        }

        if (!$code) {
            SystemLog::error('analytics', 'oauth.callback.no_code', "Codigo de autorizacao nao recebido", [
                'platform' => $platform,
                'query' => $request->query(),
            ]);

            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'error',
                    'message' => 'Autorização negada ou código não recebido.',
                    'platform' => $platform,
                    'accountsCount' => 0,
                    'brandId' => $brandId,
                ]);
            }
            return redirect()->route('analytics.connections')
                ->with('error', 'Autorização negada ou código não recebido.');
        }

        $redirectUri = url('/analytics/oauth/callback/' . $platform);

        try {
            SystemLog::info('analytics', 'oauth.callback.exchange', "Trocando code por token...", [
                'platform' => $platform,
                'redirect_uri' => $redirectUri,
                'code_preview' => substr($code, 0, 10) . '...',
            ]);

            // Trocar code por token
            $tokenData = match ($platform) {
                'google_analytics' => app(GoogleAnalyticsService::class)->exchangeCode($code, $redirectUri),
                'google_ads' => app(GoogleAdsService::class)->exchangeCode($code, $redirectUri),
                'google_search_console' => app(GoogleSearchConsoleService::class)->exchangeCode($code, $redirectUri),
                'meta_ads' => app(MetaAdsService::class)->exchangeCode($code, $redirectUri),
            };

            $accessToken = $tokenData['access_token'] ?? null;
            if (!$accessToken) {
                SystemLog::error('analytics', 'oauth.callback.no_token', "Token de acesso nao recebido", [
                    'platform' => $platform,
                    'token_keys' => array_keys($tokenData),
                    'has_error' => isset($tokenData['error']),
                    'error' => $tokenData['error'] ?? null,
                    'error_description' => $tokenData['error_description'] ?? null,
                ]);
                throw new \RuntimeException('Token de acesso não recebido. Response: ' . json_encode(array_diff_key($tokenData, ['access_token' => 1, 'refresh_token' => 1])));
            }

            SystemLog::info('analytics', 'oauth.callback.token_ok', "Token recebido com sucesso", [
                'platform' => $platform,
                'has_refresh' => !empty($tokenData['refresh_token']),
                'expires_in' => $tokenData['expires_in'] ?? 'N/A',
            ]);

            // Buscar propriedades/contas disponíveis
            SystemLog::info('analytics', 'oauth.callback.fetch', "Buscando contas/propriedades...", [
                'platform' => $platform,
            ]);

            $accounts = match ($platform) {
                'google_analytics' => app(GoogleAnalyticsService::class)->fetchProperties($accessToken),
                'google_ads' => app(GoogleAdsService::class)->fetchCustomers($accessToken),
                'google_search_console' => app(GoogleSearchConsoleService::class)->fetchSites($accessToken),
                'meta_ads' => app(MetaAdsService::class)->fetchAdAccounts($accessToken),
            };

            SystemLog::info('analytics', 'oauth.callback.accounts', count($accounts) . " conta(s) encontrada(s)", [
                'platform' => $platform,
                'count' => count($accounts),
                'accounts' => array_map(fn($a) => ['id' => $a['id'] ?? '?', 'name' => $a['name'] ?? '?'], array_slice($accounts, 0, 5)),
            ]);

            // ===== SALVAR NO BANCO (nao mais sessao) =====
            $userId = $oauthData['user_id'] ?? auth()->id();
            $expiresAt = isset($tokenData['expires_in'])
                ? now()->addSeconds($tokenData['expires_in'])
                : null;

            $discoveryToken = OAuthDiscoveredAccount::generateToken();

            // Limpar registros anteriores deste usuario/brand para analytics
            OAuthDiscoveredAccount::where('user_id', $userId)
                ->where('brand_id', $brandId)
                ->whereIn('platform', ['google_analytics', 'google_ads', 'google_search_console', 'meta_ads'])
                ->delete();

            $discovery = OAuthDiscoveredAccount::create([
                'session_token' => $discoveryToken,
                'user_id' => $userId,
                'brand_id' => $brandId,
                'platform' => $platform,
                'accounts' => $accounts,
                'token_data' => [
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'expires_at' => $expiresAt?->toIso8601String(),
                ],
                'expires_at' => now()->addMinutes(30),
            ]);

            SystemLog::info('analytics', 'oauth.callback.saved_db', "Contas salvas no banco com token", [
                'discovery_id' => $discovery->id,
                'token_prefix' => substr($discoveryToken, 0, 12) . '...',
                'count' => count($accounts),
            ]);

            // Limpar cache do state
            if ($requestState) {
                Cache::forget('analytics_oauth_' . $requestState);
            }

            // Limpar sessao de dados antigos
            session()->forget(['analytics_discovered_accounts', 'analytics_oauth_token', 'analytics_oauth_platform', 'analytics_oauth_state', 'analytics_oauth_brand_id']);

            $message = count($accounts) . ' conta(s) encontrada(s). Selecione as que deseja conectar.';

            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'success',
                    'message' => $message,
                    'platform' => $platform,
                    'accountsCount' => count($accounts),
                    'discoveryToken' => $discoveryToken,
                ]);
            }

            return redirect()->route('analytics.connections', ['brand_id' => $brandId, 'discovery_token' => $discoveryToken])
                ->with('success', $message);

        } catch (\Throwable $e) {
            SystemLog::error('analytics', 'oauth.callback.error', "ERRO no callback OAuth: {$e->getMessage()}", [
                'platform' => $platform,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => array_slice(array_map(fn($t) => ($t['file'] ?? '?') . ':' . ($t['line'] ?? '?') . ' ' . ($t['class'] ?? '') . ($t['type'] ?? '') . ($t['function'] ?? ''), $e->getTrace()), 0, 8),
            ]);

            Log::error('Analytics OAuth callback error', [
                'platform' => $platform,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $errorMessage = 'Erro na autenticação: ' . $e->getMessage();

            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'error',
                    'message' => $errorMessage,
                    'platform' => $platform,
                    'accountsCount' => 0,
                    'brandId' => $brandId,
                ]);
            }

            return redirect()->route('analytics.connections', ['brand_id' => $brandId])
                ->with('error', $errorMessage);
        }
    }

    /**
     * Salvar contas selecionadas do OAuth
     */
    public function saveOAuthAccounts(Request $request)
    {
        $request->validate([
            'brand_id' => 'required|exists:brands,id',
            'accounts' => 'required|array|min:1',
            'accounts.*.id' => 'required',
            'accounts.*.name' => 'required|string',
            'discovery_token' => 'required|string',
        ]);

        $token = $request->input('discovery_token');
        $userId = auth()->id();

        // Buscar do banco
        $discovery = OAuthDiscoveredAccount::where('session_token', $token)
            ->where('user_id', $userId)
            ->where('expires_at', '>', now())
            ->first();

        if (!$discovery) {
            SystemLog::error('analytics', 'oauth.save.expired', "Token de descoberta expirado ou invalido");
            return back()->with('error', 'Sessão OAuth expirada. Conecte novamente.');
        }

        $platform = $discovery->platform;
        $tokenData = $discovery->token_data;

        SystemLog::info('analytics', 'oauth.save', "Salvando contas Analytics OAuth", [
            'brand_id' => $request->brand_id,
            'platform' => $platform,
            'accounts_count' => count($request->accounts),
        ]);

        $saved = 0;
        foreach ($request->accounts as $account) {
            try {
                AnalyticsConnection::updateOrCreate(
                    [
                        'brand_id' => $request->brand_id,
                        'platform' => $platform,
                        'external_id' => $account['id'],
                    ],
                    [
                        'user_id' => $userId,
                        'name' => $account['name'],
                        'external_name' => $account['name'],
                        'access_token' => $tokenData['access_token'],
                        'refresh_token' => $tokenData['refresh_token'] ?? null,
                        'token_expires_at' => !empty($tokenData['expires_at'])
                            ? Carbon::parse($tokenData['expires_at'])
                            : null,
                        'config' => [
                            'property_id' => $account['id'],
                            'account_name' => $account['account_name'] ?? null,
                        ],
                        'is_active' => true,
                        'sync_status' => 'pending',
                    ]
                );
                $saved++;
            } catch (\Throwable $e) {
                SystemLog::error('analytics', 'oauth.save.account_error', "Erro ao salvar conta: {$e->getMessage()}", [
                    'account_id' => $account['id'],
                    'account_name' => $account['name'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Limpar registro do banco
        $discovery->delete();

        SystemLog::info('analytics', 'oauth.save.complete', "{$saved} conexao(oes) salva(s)", [
            'saved' => $saved,
            'platform' => $platform,
        ]);

        return back()->with('success', "{$saved} conexão(ões) salva(s) com sucesso!");
    }

    /**
     * Retorna contas descobertas do banco via AJAX
     */
    public function discoveredAccounts(Request $request): JsonResponse
    {
        $token = $request->get('token');
        $userId = auth()->id();

        if ($token) {
            $discovery = OAuthDiscoveredAccount::where('session_token', $token)
                ->where('user_id', $userId)
                ->where('expires_at', '>', now())
                ->first();
        } else {
            $discovery = OAuthDiscoveredAccount::where('user_id', $userId)
                ->whereIn('platform', ['google_analytics', 'google_ads', 'google_search_console', 'meta_ads'])
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->first();
        }

        if ($discovery) {
            return response()->json([
                'accounts' => $discovery->accounts,
                'platform' => $discovery->platform,
                'token' => $discovery->session_token,
            ]);
        }

        return response()->json([
            'accounts' => [],
            'platform' => null,
            'token' => null,
        ]);
    }

    /**
     * Adicionar conexão manual
     */
    public function storeConnection(Request $request)
    {
        $platform = $request->input('platform');

        // Validacao especifica por plataforma
        if ($platform === 'woocommerce') {
            $request->validate([
                'brand_id' => 'required|exists:brands,id',
                'platform' => 'required|string|in:woocommerce',
                'name' => 'required|string|max:255',
                'store_url' => 'required|url|max:500',
                'consumer_key' => 'required|string|max:255',
                'consumer_secret' => 'required|string|max:255',
                'order_statuses' => 'nullable|array',
                'order_statuses.*' => 'string|max:100',
            ]);

            // Testar conexao antes de salvar
            $wcService = app(WooCommerceService::class);
            $test = $wcService->testConnection($request->store_url, $request->consumer_key, $request->consumer_secret);

            if (!$test['success']) {
                return back()->with('error', 'Erro ao conectar WooCommerce: ' . $test['error']);
            }

            $storeUrl = rtrim($request->store_url, '/');

            // Processar status de pedido (remover prefixo "wc-" se presente)
            $orderStatuses = collect($request->order_statuses ?? [])
                ->map(fn($s) => str_replace('wc-', '', trim($s)))
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            AnalyticsConnection::create([
                'brand_id' => $request->brand_id,
                'user_id' => auth()->id(),
                'platform' => 'woocommerce',
                'name' => $request->name,
                'external_id' => $storeUrl,
                'external_name' => $test['store_name'] ?? $request->name,
                'is_active' => true,
                'sync_status' => 'pending',
                'config' => [
                    'store_url' => $storeUrl,
                    'consumer_key' => $request->consumer_key,
                    'consumer_secret' => $request->consumer_secret,
                    'wc_version' => $test['wc_version'] ?? null,
                    'currency' => $test['currency'] ?? 'BRL',
                    'order_statuses' => $orderStatuses,
                ],
            ]);

            return back()->with('success', 'WooCommerce conectado com sucesso! Versão: ' . ($test['wc_version'] ?? 'desconhecida'));
        }

        // Validacao padrao para outras plataformas
        $request->validate([
            'brand_id' => 'required|exists:brands,id',
            'platform' => 'required|string|in:google_analytics,google_ads,google_search_console,meta_ads',
            'name' => 'required|string|max:255',
            'external_id' => 'required|string|max:255',
            'access_token' => 'nullable|string',
            'refresh_token' => 'nullable|string',
        ]);

        AnalyticsConnection::create([
            'brand_id' => $request->brand_id,
            'user_id' => auth()->id(),
            'platform' => $request->platform,
            'name' => $request->name,
            'external_id' => $request->external_id,
            'external_name' => $request->name,
            'access_token' => $request->access_token,
            'refresh_token' => $request->refresh_token,
            'is_active' => true,
            'sync_status' => 'pending',
            'config' => ['property_id' => $request->external_id],
        ]);

        return back()->with('success', 'Conexão adicionada com sucesso!');
    }

    /**
     * Testar conexao WooCommerce (AJAX)
     */
    public function testWooCommerce(Request $request): JsonResponse
    {
        $request->validate([
            'store_url' => 'required|url',
            'consumer_key' => 'required|string',
            'consumer_secret' => 'required|string',
        ]);

        $wcService = app(WooCommerceService::class);
        $result = $wcService->testConnection($request->store_url, $request->consumer_key, $request->consumer_secret);

        return response()->json($result);
    }

    /**
     * Buscar status de pedido disponíveis na loja WooCommerce
     */
    public function fetchWooCommerceStatuses(Request $request): JsonResponse
    {
        $request->validate([
            'store_url' => 'required|url',
            'consumer_key' => 'required|string',
            'consumer_secret' => 'required|string',
        ]);

        try {
            $wcService = app(WooCommerceService::class);
            $statuses = $wcService->fetchOrderStatuses($request->store_url, $request->consumer_key, $request->consumer_secret);

            return response()->json([
                'success' => true,
                'statuses' => $statuses,
                'default_statuses' => WooCommerceService::DEFAULT_ORDER_STATUSES,
            ]);
        } catch (\Throwable $e) {
            SystemLog::error('analytics', 'woocommerce.statuses.controller_error', "Erro ao buscar status WooCommerce: {$e->getMessage()}", [
                'store_url' => $request->store_url,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'statuses' => [],
            ], 500);
        }
    }

    /**
     * Atualizar status de pedido de uma conexão WooCommerce existente
     */
    public function updateWooCommerceStatuses(Request $request, AnalyticsConnection $connection): JsonResponse
    {
        SystemLog::debug('analytics', 'woocommerce.statuses.update_request', "Recebida requisição para atualizar status da conexão #{$connection->id}", [
            'connection_id' => $connection->id,
            'platform' => $connection->platform,
            'received_statuses' => $request->order_statuses,
        ]);

        if ($connection->platform !== 'woocommerce') {
            return response()->json(['success' => false, 'error' => 'Conexão não é WooCommerce'], 400);
        }

        $request->validate([
            'order_statuses' => 'required|array|min:1',
            'order_statuses.*' => 'string|max:100',
        ]);

        $orderStatuses = collect($request->order_statuses)
            ->map(fn($s) => str_replace('wc-', '', trim($s)))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        try {
            $config = $connection->config ?? [];

            SystemLog::debug('analytics', 'woocommerce.statuses.config_before', "Config antes da atualização", [
                'connection_id' => $connection->id,
                'config_keys' => array_keys($config),
                'old_statuses' => $config['order_statuses'] ?? 'não definido',
            ]);

            $config['order_statuses'] = $orderStatuses;

            $connection->config = $config;
            $connection->save();

            // Recarregar para confirmar que salvou
            $connection->refresh();
            $savedStatuses = $connection->config['order_statuses'] ?? [];

            SystemLog::info('analytics', 'woocommerce.statuses.updated', "Status WooCommerce atualizados para: {$connection->name}", [
                'connection_id' => $connection->id,
                'order_statuses' => $orderStatuses,
                'saved_statuses' => $savedStatuses,
                'match' => $savedStatuses === $orderStatuses,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Status de pedido atualizados com sucesso',
                'order_statuses' => $savedStatuses,
            ]);
        } catch (\Throwable $e) {
            SystemLog::error('analytics', 'woocommerce.statuses.update_error', "Erro ao salvar status: {$e->getMessage()}", [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Criar investimento manual
     */
    public function storeManualEntry(Request $request)
    {
        $request->validate([
            'brand_id' => 'required|exists:brands,id',
            'platform' => 'required|string|in:' . implode(',', array_keys(ManualAdEntry::platformOptions())),
            'platform_label' => 'nullable|string|max:255',
            'date_start' => 'required|date',
            'date_end' => 'required|date|after_or_equal:date_start',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:1000',
        ]);

        ManualAdEntry::create([
            'brand_id' => $request->brand_id,
            'user_id' => auth()->id(),
            'platform' => $request->platform,
            'platform_label' => $request->platform === 'other' ? $request->platform_label : null,
            'date_start' => $request->date_start,
            'date_end' => $request->date_end,
            'amount' => $request->amount,
            'description' => $request->description,
        ]);

        // Recalcular sumarios do periodo afetado
        $this->syncService->rebuildDailySummaries(
            $request->brand_id,
            $request->date_start,
            $request->date_end
        );

        return back()->with('success', 'Investimento manual cadastrado com sucesso!');
    }

    /**
     * Atualizar investimento manual
     */
    public function updateManualEntry(Request $request, ManualAdEntry $entry)
    {
        $request->validate([
            'platform' => 'required|string|in:' . implode(',', array_keys(ManualAdEntry::platformOptions())),
            'platform_label' => 'nullable|string|max:255',
            'date_start' => 'required|date',
            'date_end' => 'required|date|after_or_equal:date_start',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:1000',
        ]);

        // Guardar periodo antigo para recalcular
        $oldStart = $entry->date_start->format('Y-m-d');
        $oldEnd = $entry->date_end->format('Y-m-d');

        $entry->update([
            'platform' => $request->platform,
            'platform_label' => $request->platform === 'other' ? $request->platform_label : null,
            'date_start' => $request->date_start,
            'date_end' => $request->date_end,
            'amount' => $request->amount,
            'description' => $request->description,
        ]);

        // Recalcular sumarios do periodo antigo e novo
        $minStart = min($oldStart, $request->date_start);
        $maxEnd = max($oldEnd, $request->date_end);
        $this->syncService->rebuildDailySummaries($entry->brand_id, $minStart, $maxEnd);

        return back()->with('success', 'Investimento manual atualizado!');
    }

    /**
     * Remover investimento manual
     */
    public function destroyManualEntry(ManualAdEntry $entry)
    {
        $brandId = $entry->brand_id;
        $start = $entry->date_start->format('Y-m-d');
        $end = $entry->date_end->format('Y-m-d');

        $entry->delete();

        // Recalcular sumarios
        $this->syncService->rebuildDailySummaries($brandId, $start, $end);

        return back()->with('success', 'Investimento manual removido!');
    }

    /**
     * Toggle ativo/inativo
     */
    public function toggleConnection(AnalyticsConnection $connection)
    {
        $connection->update(['is_active' => !$connection->is_active]);
        return back()->with('success', $connection->is_active ? 'Conexão ativada.' : 'Conexão desativada.');
    }

    /**
     * Remover conexão
     */
    public function destroyConnection(AnalyticsConnection $connection)
    {
        $connection->delete();
        return back()->with('success', 'Conexão removida.');
    }

    /**
     * Sincronizar dados de uma conexão
     */
    public function syncConnection(Request $request, AnalyticsConnection $connection)
    {
        $startDate = $request->get('start_date', now()->subDays(30)->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        SystemLog::info('analytics', 'sync.start', "Sincronizando conexao #{$connection->id} ({$connection->platform})", [
            'connection_id' => $connection->id,
            'platform' => $connection->platform,
            'name' => $connection->name,
            'external_id' => $connection->external_id,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        try {
            $result = $this->syncService->syncConnection($connection, $startDate, $endDate);

            if ($result['success']) {
                // Recalcular sumários
                $this->syncService->rebuildDailySummaries($connection->brand_id, $startDate, $endDate);
                SystemLog::info('analytics', 'sync.complete', "Sincronizacao concluida: {$result['synced']} pontos", [
                    'connection_id' => $connection->id,
                    'synced' => $result['synced'],
                ]);
                return back()->with('success', "Sincronização concluída! {$result['synced']} pontos de dados atualizados.");
            }

            SystemLog::error('analytics', 'sync.failed', "Sincronizacao falhou: " . ($result['error'] ?? 'Erro desconhecido'), [
                'connection_id' => $connection->id,
                'platform' => $connection->platform,
                'error' => $result['error'] ?? 'Desconhecido',
            ]);

            return back()->with('error', 'Erro na sincronização: ' . ($result['error'] ?? 'Erro desconhecido'));
        } catch (\Throwable $e) {
            SystemLog::error('analytics', 'sync.exception', "EXCECAO na sincronizacao: {$e->getMessage()}", [
                'connection_id' => $connection->id,
                'platform' => $connection->platform,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => array_slice(array_map(fn($t) => ($t['file'] ?? '?') . ':' . ($t['line'] ?? '?') . ' ' . ($t['class'] ?? '') . ($t['type'] ?? '') . ($t['function'] ?? ''), $e->getTrace()), 0, 8),
            ]);

            $connection->update([
                'sync_status' => 'error',
                'sync_error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Erro na sincronização: ' . $e->getMessage());
        }
    }

    /**
     * Sincronizar todas as conexões de uma marca
     */
    public function syncAll(Request $request)
    {
        $brandId = $request->get('brand_id') ?? session('active_brand_id');
        if (!$brandId) {
            return back()->with('error', 'Nenhuma marca selecionada.');
        }

        $startDate = $request->get('start_date', now()->subDays(30)->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        SystemLog::info('analytics', 'sync_all.start', "Sincronizando todas as conexoes da brand #{$brandId}");

        try {
            $results = $this->syncService->syncBrand($brandId, $startDate, $endDate);

            $successCount = collect($results)->where('success', true)->count();
            $errorCount = collect($results)->where('success', false)->count();

            $message = "{$successCount} plataforma(s) sincronizada(s) com sucesso.";
            if ($errorCount > 0) {
                $errors = collect($results)->where('success', false)->map(fn($r, $k) => "{$k}: " . ($r['error'] ?? '?'))->values()->toArray();
                $message .= " {$errorCount} com erro.";
                SystemLog::warning('analytics', 'sync_all.partial', $message, ['errors' => $errors]);
            } else {
                SystemLog::info('analytics', 'sync_all.complete', $message);
            }

            return back()->with($errorCount > 0 ? 'warning' : 'success', $message);
        } catch (\Throwable $e) {
            SystemLog::error('analytics', 'sync_all.exception', "EXCECAO no sync all: {$e->getMessage()}", [
                'brand_id' => $brandId,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return back()->with('error', 'Erro na sincronização: ' . $e->getMessage());
        }
    }

    /**
     * Detalhamento de Website (GA4)
     */
    public function website(Request $request)
    {
        $brandId = $request->get('brand_id') ?? session('active_brand_id');
        $brand = $brandId ? Brand::find($brandId) : Brand::first();

        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        $startDate = $request->get('start_date', now()->subDays(29)->format('Y-m-d'));

        $summaries = $brand ? AnalyticsDailySummary::where('brand_id', $brand->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get() : collect();

        $topDimensions = [];
        if ($brand) {
            $topDimensions = [
                'sources' => AnalyticsDataPoint::where('brand_id', $brand->id)
                    ->where('platform', 'google_analytics')
                    ->where('dimension_key', 'source')
                    ->where('date', $endDate)
                    ->orderByDesc('value')
                    ->limit(15)
                    ->get(['dimension_value as name', 'value', 'extra'])->toArray(),
                'mediums' => AnalyticsDataPoint::where('brand_id', $brand->id)
                    ->where('platform', 'google_analytics')
                    ->where('dimension_key', 'medium')
                    ->where('date', $endDate)
                    ->orderByDesc('value')
                    ->limit(15)
                    ->get(['dimension_value as name', 'value', 'extra'])->toArray(),
                'pages' => AnalyticsDataPoint::where('brand_id', $brand->id)
                    ->where('platform', 'google_analytics')
                    ->where('dimension_key', 'page')
                    ->where('date', $endDate)
                    ->orderByDesc('value')
                    ->limit(20)
                    ->get(['dimension_value as name', 'value', 'extra'])->toArray(),
                'devices' => AnalyticsDataPoint::where('brand_id', $brand->id)
                    ->where('platform', 'google_analytics')
                    ->where('dimension_key', 'device')
                    ->where('date', $endDate)
                    ->orderByDesc('value')
                    ->limit(10)
                    ->get(['dimension_value as name', 'value', 'extra'])->toArray(),
                'countries' => AnalyticsDataPoint::where('brand_id', $brand->id)
                    ->where('platform', 'google_analytics')
                    ->where('dimension_key', 'country')
                    ->where('date', $endDate)
                    ->orderByDesc('value')
                    ->limit(15)
                    ->get(['dimension_value as name', 'value', 'extra'])->toArray(),
            ];
        }

        return Inertia::render('Analytics/Website', [
            'brand' => $brand,
            'summaries' => $summaries,
            'topDimensions' => $topDimensions,
            'filters' => ['start_date' => $startDate, 'end_date' => $endDate],
        ]);
    }

    /**
     * Detalhamento de Ads
     */
    public function ads(Request $request)
    {
        $brandId = $request->get('brand_id') ?? session('active_brand_id');
        $brand = $brandId ? Brand::find($brandId) : Brand::first();

        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        $startDate = $request->get('start_date', now()->subDays(29)->format('Y-m-d'));

        $summaries = $brand ? AnalyticsDailySummary::where('brand_id', $brand->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get() : collect();

        $campaigns = [];
        if ($brand) {
            $campaigns = AnalyticsDataPoint::where('brand_id', $brand->id)
                ->whereIn('platform', ['google_ads', 'meta_ads'])
                ->where('dimension_key', 'campaign')
                ->where('metric_key', 'spend')
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(20)
                ->get(['dimension_value as name', 'value', 'platform', 'extra'])
                ->toArray();
        }

        return Inertia::render('Analytics/Ads', [
            'brand' => $brand,
            'summaries' => $summaries,
            'campaigns' => $campaigns,
            'filters' => ['start_date' => $startDate, 'end_date' => $endDate],
        ]);
    }

    /**
     * Detalhamento de SEO (Search Console)
     */
    public function seo(Request $request)
    {
        $brandId = $request->get('brand_id') ?? session('active_brand_id');
        $brand = $brandId ? Brand::find($brandId) : Brand::first();

        $endDate = $request->get('end_date', now()->subDays(2)->format('Y-m-d'));
        $startDate = $request->get('start_date', now()->subDays(31)->format('Y-m-d'));

        $summaries = $brand ? AnalyticsDailySummary::where('brand_id', $brand->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get() : collect();

        $topDimensions = [];
        if ($brand) {
            $topDimensions = [
                'queries' => AnalyticsDataPoint::where('brand_id', $brand->id)
                    ->where('platform', 'google_search_console')
                    ->where('dimension_key', 'query')
                    ->where('date', $endDate)
                    ->orderByDesc('value')
                    ->limit(25)
                    ->get(['dimension_value as name', 'value', 'extra'])->toArray(),
                'pages' => AnalyticsDataPoint::where('brand_id', $brand->id)
                    ->where('platform', 'google_search_console')
                    ->where('dimension_key', 'page')
                    ->where('date', $endDate)
                    ->orderByDesc('value')
                    ->limit(20)
                    ->get(['dimension_value as name', 'value', 'extra'])->toArray(),
                'devices' => AnalyticsDataPoint::where('brand_id', $brand->id)
                    ->where('platform', 'google_search_console')
                    ->where('dimension_key', 'device')
                    ->where('date', $endDate)
                    ->orderByDesc('value')
                    ->limit(10)
                    ->get(['dimension_value as name', 'value', 'extra'])->toArray(),
                'countries' => AnalyticsDataPoint::where('brand_id', $brand->id)
                    ->where('platform', 'google_search_console')
                    ->where('dimension_key', 'country')
                    ->where('date', $endDate)
                    ->orderByDesc('value')
                    ->limit(15)
                    ->get(['dimension_value as name', 'value', 'extra'])->toArray(),
            ];
        }

        return Inertia::render('Analytics/Seo', [
            'brand' => $brand,
            'summaries' => $summaries,
            'topDimensions' => $topDimensions,
            'filters' => ['start_date' => $startDate, 'end_date' => $endDate],
        ]);
    }

    /**
     * Verifica quais plataformas de analytics têm OAuth configurado
     */
    protected function checkOAuthConfig(): array
    {
        $hasGoogle = !empty(\App\Models\Setting::get('oauth', 'google_client_id'))
            || !empty(config('social_oauth.google.client_id'));
        $hasMeta = !empty(\App\Models\Setting::get('oauth', 'meta_app_id'))
            || !empty(config('social_oauth.meta.app_id'));

        return [
            'google_analytics' => $hasGoogle,
            'google_ads' => $hasGoogle,
            'google_search_console' => $hasGoogle,
            'meta_ads' => $hasMeta,
        ];
    }
}

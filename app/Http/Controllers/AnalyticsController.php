<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsConnection;
use App\Models\AnalyticsDataPoint;
use App\Models\AnalyticsDailySummary;
use App\Models\Brand;
use App\Services\Analytics\AnalyticsSyncService;
use App\Services\Analytics\GoogleAdsService;
use App\Services\Analytics\GoogleAnalyticsService;
use App\Services\Analytics\GoogleSearchConsoleService;
use App\Services\Analytics\MetaAdsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
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

        // Contas descobertas via OAuth (na sessão)
        $discoveredAccounts = session('analytics_discovered_accounts', []);
        $discoveredPlatform = session('analytics_oauth_platform');

        $brands = Brand::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Analytics/Connections', [
            'brand' => $brand,
            'brands' => $brands,
            'connections' => $connections,
            'oauthConfigured' => $oauthConfigured,
            'discoveredAccounts' => $discoveredAccounts,
            'discoveredPlatform' => $discoveredPlatform,
            'platforms' => AnalyticsConnection::platformLabels(),
            'platformColors' => AnalyticsConnection::platformColors(),
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
        session([
            'analytics_oauth_state' => $state,
            'analytics_oauth_platform' => $platform,
            'analytics_oauth_brand_id' => $request->brand_id,
            'analytics_oauth_popup' => $request->boolean('popup', false),
        ]);

        $redirectUri = url('/analytics/oauth/callback/' . $platform);

        $url = match ($platform) {
            'google_analytics' => app(GoogleAnalyticsService::class)->getAuthorizationUrl($redirectUri, $state),
            'google_ads' => app(GoogleAdsService::class)->getAuthorizationUrl($redirectUri, $state),
            'google_search_console' => app(GoogleSearchConsoleService::class)->getAuthorizationUrl($redirectUri, $state),
            'meta_ads' => app(MetaAdsService::class)->getAuthorizationUrl($redirectUri, $state),
            default => throw new \InvalidArgumentException('Plataforma não suportada'),
        };

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
        $state = session('analytics_oauth_state');
        $brandId = session('analytics_oauth_brand_id');
        $isPopup = session('analytics_oauth_popup', false);

        if ($request->get('state') !== $state) {
            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'error',
                    'message' => 'Estado OAuth inválido. Tente novamente.',
                    'platform' => $platform,
                    'accountsCount' => 0,
                    'brandId' => $brandId,
                ]);
            }
            return redirect()->route('analytics.connections')
                ->with('error', 'Estado OAuth inválido. Tente novamente.');
        }

        $code = $request->get('code');
        if (!$code) {
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
            // Trocar code por token
            $tokenData = match ($platform) {
                'google_analytics' => app(GoogleAnalyticsService::class)->exchangeCode($code, $redirectUri),
                'google_ads' => app(GoogleAdsService::class)->exchangeCode($code, $redirectUri),
                'google_search_console' => app(GoogleSearchConsoleService::class)->exchangeCode($code, $redirectUri),
                'meta_ads' => app(MetaAdsService::class)->exchangeCode($code, $redirectUri),
            };

            $accessToken = $tokenData['access_token'] ?? null;
            if (!$accessToken) {
                throw new \RuntimeException('Token de acesso não recebido');
            }

            // Buscar propriedades/contas disponíveis
            $accounts = match ($platform) {
                'google_analytics' => app(GoogleAnalyticsService::class)->fetchProperties($accessToken),
                'google_ads' => app(GoogleAdsService::class)->fetchCustomers($accessToken),
                'google_search_console' => app(GoogleSearchConsoleService::class)->fetchSites($accessToken),
                'meta_ads' => app(MetaAdsService::class)->fetchAdAccounts($accessToken),
            };

            // Salvar na sessão para seleção
            session([
                'analytics_discovered_accounts' => $accounts,
                'analytics_oauth_token' => $tokenData,
                'analytics_oauth_platform' => $platform,
            ]);

            $message = count($accounts) . ' conta(s) encontrada(s). Selecione as que deseja conectar.';

            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'success',
                    'message' => $message,
                    'platform' => $platform,
                    'accountsCount' => count($accounts),
                    'brandId' => $brandId,
                ]);
            }

            return redirect()->route('analytics.connections', ['brand_id' => $brandId])
                ->with('success', $message);

        } catch (\Throwable $e) {
            Log::error('Analytics OAuth callback error', [
                'platform' => $platform,
                'error' => $e->getMessage(),
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
        ]);

        $platform = session('analytics_oauth_platform');
        $tokenData = session('analytics_oauth_token');

        if (!$platform || !$tokenData) {
            return back()->with('error', 'Sessão OAuth expirada. Conecte novamente.');
        }

        $saved = 0;
        foreach ($request->accounts as $account) {
            AnalyticsConnection::updateOrCreate(
                [
                    'brand_id' => $request->brand_id,
                    'platform' => $platform,
                    'external_id' => $account['id'],
                ],
                [
                    'user_id' => auth()->id(),
                    'name' => $account['name'],
                    'external_name' => $account['name'],
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'token_expires_at' => isset($tokenData['expires_in'])
                        ? now()->addSeconds($tokenData['expires_in'])
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
        }

        // Limpar sessão
        session()->forget([
            'analytics_discovered_accounts',
            'analytics_oauth_token',
            'analytics_oauth_platform',
            'analytics_oauth_state',
            'analytics_oauth_brand_id',
        ]);

        return back()->with('success', "{$saved} conexão(ões) salva(s) com sucesso!");
    }

    /**
     * Adicionar conexão manual
     */
    public function storeConnection(Request $request)
    {
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

        $result = $this->syncService->syncConnection($connection, $startDate, $endDate);

        if ($result['success']) {
            // Recalcular sumários
            $this->syncService->rebuildDailySummaries($connection->brand_id, $startDate, $endDate);
            return back()->with('success', "Sincronização concluída! {$result['synced']} pontos de dados atualizados.");
        }

        return back()->with('error', 'Erro na sincronização: ' . ($result['error'] ?? 'Erro desconhecido'));
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

        $results = $this->syncService->syncBrand($brandId, $startDate, $endDate);

        $successCount = collect($results)->where('success', true)->count();
        $errorCount = collect($results)->where('success', false)->count();

        $message = "{$successCount} plataforma(s) sincronizada(s) com sucesso.";
        if ($errorCount > 0) {
            $message .= " {$errorCount} com erro.";
        }

        return back()->with($errorCount > 0 ? 'warning' : 'success', $message);
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

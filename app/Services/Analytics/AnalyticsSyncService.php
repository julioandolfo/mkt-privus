<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsConnection;
use App\Models\AnalyticsDailySummary;
use App\Models\AnalyticsDataPoint;
use App\Models\ManualAdEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AnalyticsSyncService
{
    protected GoogleAnalyticsService $gaService;
    protected MetaAdsService $metaAdsService;
    protected GoogleSearchConsoleService $gscService;
    protected GoogleAdsService $googleAdsService;
    protected WooCommerceService $wooCommerceService;

    public function __construct(
        GoogleAnalyticsService $gaService,
        MetaAdsService $metaAdsService,
        GoogleSearchConsoleService $gscService,
        GoogleAdsService $googleAdsService,
        WooCommerceService $wooCommerceService
    ) {
        $this->gaService = $gaService;
        $this->metaAdsService = $metaAdsService;
        $this->gscService = $gscService;
        $this->googleAdsService = $googleAdsService;
        $this->wooCommerceService = $wooCommerceService;
    }

    /**
     * Sincroniza todas as conexões ativas de uma marca (ou todas se brandId = null)
     */
    public function syncBrand(?int $brandId, ?string $startDate = null, ?string $endDate = null): array
    {
        $connections = AnalyticsConnection::when($brandId, fn($q) => $q->where('brand_id', $brandId))
            ->where('is_active', true)
            ->get();

        $results = [];

        foreach ($connections as $connection) {
            $results[$connection->platform] = $this->syncConnection($connection, $startDate, $endDate);
        }

        $start = $startDate ?? now()->subDays(30)->format('Y-m-d');
        $end = $endDate ?? now()->format('Y-m-d');

        // Recalcular sumários diários — sempre por marca para evitar duplicatas com brand_id NULL
        if ($brandId) {
            $this->rebuildDailySummaries($brandId, $start, $end);
        } else {
            // Quando global, recalcular para cada marca individualmente
            $brandIds = $connections->pluck('brand_id')->filter()->unique();
            foreach ($brandIds as $bId) {
                $this->rebuildDailySummaries($bId, $start, $end);
            }
        }

        return $results;
    }

    /**
     * Sincroniza uma conexão específica
     */
    public function syncConnection(AnalyticsConnection $connection, ?string $startDate = null, ?string $endDate = null): array
    {
        return match ($connection->platform) {
            'google_analytics' => $this->gaService->syncData($connection, $startDate, $endDate),
            'meta_ads' => $this->metaAdsService->syncData($connection, $startDate, $endDate),
            'google_search_console' => $this->gscService->syncData($connection, $startDate, $endDate),
            'google_ads' => $this->googleAdsService->syncData($connection, $startDate, $endDate),
            'woocommerce' => $this->wooCommerceService->syncData($connection, $startDate, $endDate),
            default => ['success' => false, 'error' => "Plataforma {$connection->platform} não suportada"],
        };
    }

    /**
     * Reconstrói os sumários diários para uma marca num período (ou globais se brandId = null)
     */
    public function rebuildDailySummaries(?int $brandId, string $startDate, string $endDate): void
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // Pre-carregar manual entries do periodo para performance
        $manualEntries = ManualAdEntry::when($brandId, fn($q) => $q->where('brand_id', $brandId))
            ->where(function ($q) use ($startDate, $endDate) {
                $q->where('date_start', '<=', $endDate)
                  ->where('date_end', '>=', $startDate);
            })
            ->get();

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateStr = $date->format('Y-m-d');

            $gaData = AnalyticsDataPoint::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                ->where('platform', 'google_analytics')
                ->where('date', $dateStr)
                ->whereNull('dimension_key')
                ->get()
                ->keyBy('metric_key');

            $adData = AnalyticsDataPoint::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                ->whereIn('platform', ['meta_ads', 'google_ads'])
                ->where('date', $dateStr)
                ->whereNull('dimension_key')
                ->get();

            // Dados de custo do Google Ads via GA4 (quando Ads está vinculado ao GA4)
            $ga4AdData = AnalyticsDataPoint::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                ->where('platform', 'google_analytics')
                ->where('date', $dateStr)
                ->whereNull('dimension_key')
                ->whereIn('metric_key', ['ga4_ad_cost', 'ga4_ad_clicks', 'ga4_ad_impressions', 'ga4_ad_conversions', 'ga4_ad_roas', 'ga4_ad_cpc', 'ga4_ad_cost_per_conversion'])
                ->get()
                ->keyBy('metric_key');

            $scData = AnalyticsDataPoint::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                ->where('platform', 'google_search_console')
                ->where('date', $dateStr)
                ->whereNull('dimension_key')
                ->get()
                ->keyBy('metric_key');

            $wcData = AnalyticsDataPoint::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                ->where('platform', 'woocommerce')
                ->where('date', $dateStr)
                ->whereNull('dimension_key')
                ->get()
                ->keyBy('metric_key');

            // Agregar dados de ads diretos (soma de API Google Ads + Meta Ads)
            $directAdSpend = $adData->where('metric_key', 'spend')->sum('value');
            $directImpressions = $adData->where('metric_key', 'impressions')->sum('value');
            $directClicks = $adData->where('metric_key', 'clicks')->sum('value');
            $directConversions = $adData->where('metric_key', 'conversions')->sum('value');
            $directAdRevenue = $adData->where('metric_key', 'revenue')->sum('value') + $adData->where('metric_key', 'conversion_value')->sum('value');

            // Se não tem dados diretos do Google Ads, usar dados via GA4 (quando Ads vinculado ao Analytics)
            $ga4AdCost = floatval($ga4AdData->get('ga4_ad_cost')?->value ?? 0);
            $ga4AdClicks = floatval($ga4AdData->get('ga4_ad_clicks')?->value ?? 0);
            $ga4AdImpressions = floatval($ga4AdData->get('ga4_ad_impressions')?->value ?? 0);
            $ga4AdConversions = floatval($ga4AdData->get('ga4_ad_conversions')?->value ?? 0);

            // Verificar se já tem dados diretos do Google Ads (plataforma google_ads)
            $hasDirectGoogleAds = $adData->where('metric_key', 'spend')
                ->filter(fn($dp) => $dp->platform === 'google_ads' && $dp->value > 0)->isNotEmpty();

            // Se NÃO tem dados diretos do Google Ads mas TEM via GA4, somar o GA4
            if (!$hasDirectGoogleAds && $ga4AdCost > 0) {
                $apiAdSpend = $directAdSpend + $ga4AdCost;
                $totalImpressions = $directImpressions + $ga4AdImpressions;
                $totalClicks = $directClicks + $ga4AdClicks;
                $totalConversions = $directConversions + $ga4AdConversions;
                $adRevenue = $directAdRevenue;
            } else {
                // Usar apenas dados diretos (evitar duplicar se já tem Google Ads API)
                $apiAdSpend = $directAdSpend;
                $totalImpressions = $directImpressions;
                $totalClicks = $directClicks;
                $totalConversions = $directConversions;
                $adRevenue = $directAdRevenue;
            }

            $adCtr = $totalImpressions > 0 ? ($totalClicks / $totalImpressions) * 100 : 0;
            $adCpc = $totalClicks > 0 ? $apiAdSpend / $totalClicks : 0;
            $adRoas = $apiAdSpend > 0 ? $adRevenue / $apiAdSpend : 0;

            // Investimentos manuais distribuidos para este dia
            $manualSpend = $manualEntries
                ->filter(fn($e) => $e->date_start->lte($date) && $e->date_end->gte($date))
                ->sum(fn($e) => $e->dailyAmount());

            // Total de investimento (API + manual)
            $totalSpend = $apiAdSpend + $manualSpend;

            // WooCommerce
            $wcOrders = intval($wcData->get('wc_orders')?->value ?? 0);
            $wcRevenue = floatval($wcData->get('wc_revenue')?->value ?? 0);
            $wcAvgOrderValue = floatval($wcData->get('wc_avg_order_value')?->value ?? 0);
            $wcItemsSold = intval($wcData->get('wc_items_sold')?->value ?? 0);
            $wcRefunds = floatval($wcData->get('wc_refunds')?->value ?? 0);
            $wcShipping = floatval($wcData->get('wc_shipping')?->value ?? 0);
            $wcTax = floatval($wcData->get('wc_tax')?->value ?? 0);
            $wcNewCustomers = intval($wcData->get('wc_new_customers')?->value ?? 0);
            $wcCouponsUsed = intval($wcData->get('wc_coupons_used')?->value ?? 0);

            // ROAS Real (receita WooCommerce / investimento total)
            $realRoas = $totalSpend > 0 && $wcRevenue > 0 ? $wcRevenue / $totalSpend : 0;

            AnalyticsDailySummary::updateOrCreate(
                ['brand_id' => $brandId, 'date' => $dateStr],
                [
                    // Website (GA4)
                    'sessions' => intval($gaData->get('sessions')?->value ?? 0),
                    'users' => intval($gaData->get('users')?->value ?? 0),
                    'new_users' => intval($gaData->get('new_users')?->value ?? 0),
                    'pageviews' => intval($gaData->get('pageviews')?->value ?? 0),
                    'bounce_rate' => floatval($gaData->get('bounce_rate')?->value ?? 0),
                    'avg_session_duration' => floatval($gaData->get('avg_session_duration')?->value ?? 0),
                    // Ads (API)
                    'ad_spend' => $apiAdSpend,
                    'ad_impressions' => intval($totalImpressions),
                    'ad_clicks' => intval($totalClicks),
                    'ad_conversions' => intval($totalConversions),
                    'ad_revenue' => $adRevenue,
                    'ad_ctr' => $adCtr,
                    'ad_cpc' => $adCpc,
                    'ad_roas' => $adRoas,
                    // SEO
                    'search_impressions' => intval($scData->get('search_impressions')?->value ?? 0),
                    'search_clicks' => intval($scData->get('search_clicks')?->value ?? 0),
                    'search_ctr' => floatval($scData->get('search_ctr')?->value ?? 0),
                    'search_position' => floatval($scData->get('search_position')?->value ?? 0),
                    // E-commerce (WooCommerce)
                    'wc_orders' => $wcOrders,
                    'wc_revenue' => $wcRevenue,
                    'wc_avg_order_value' => $wcAvgOrderValue,
                    'wc_items_sold' => $wcItemsSold,
                    'wc_refunds' => $wcRefunds,
                    'wc_shipping' => $wcShipping,
                    'wc_tax' => $wcTax,
                    'wc_new_customers' => $wcNewCustomers,
                    'wc_coupons_used' => $wcCouponsUsed,
                    // Investimentos e ROAS real
                    'manual_ad_spend' => round($manualSpend, 2),
                    'total_spend' => round($totalSpend, 2),
                    'real_roas' => round($realRoas, 2),
                ]
            );
        }
    }

    /**
     * Retorna dados do dashboard para uma marca (ou global se brandId = null)
     */
    public function getDashboardData(?int $brandId, string $startDate, string $endDate, ?string $compareStartDate = null, ?string $compareEndDate = null): array
    {
        // Período principal
        $summaries = AnalyticsDailySummary::when($brandId, fn($q) => $q->where('brand_id', $brandId))
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get();

        // Período de comparação
        $compareSummaries = null;
        if ($compareStartDate && $compareEndDate) {
            $compareSummaries = AnalyticsDailySummary::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                ->whereBetween('date', [$compareStartDate, $compareEndDate])
                ->orderBy('date')
                ->get();
        }

        // KPIs
        $kpis = $this->calculateKpis($summaries, $compareSummaries);

        // Dados para gráficos (séries temporais)
        $charts = $this->buildChartData($summaries);

        // Top sources/pages/queries
        $topDimensions = $this->getTopDimensions($brandId, $startDate, $endDate);

        // Conexões ativas
        $connections = AnalyticsConnection::when($brandId, fn($q) => $q->where('brand_id', $brandId))
            ->where('is_active', true)
            ->get(['id', 'platform', 'name', 'last_synced_at', 'sync_status', 'sync_error']);

        return [
            'kpis' => $kpis,
            'charts' => $charts,
            'topDimensions' => $topDimensions,
            'connections' => $connections,
            'period' => ['start' => $startDate, 'end' => $endDate],
            'comparePeriod' => $compareStartDate ? ['start' => $compareStartDate, 'end' => $compareEndDate] : null,
        ];
    }

    /**
     * Calcula KPIs com comparação
     */
    protected function calculateKpis($summaries, $compareSummaries = null): array
    {
        $calc = fn($collection, $field) => $collection->sum($field);
        $avg = fn($collection, $field) => $collection->avg($field) ?? 0;

        $kpis = [
            ['key' => 'sessions', 'label' => 'Sessões', 'value' => $calc($summaries, 'sessions'), 'format' => 'number', 'icon' => 'chart-bar', 'color' => 'blue'],
            ['key' => 'users', 'label' => 'Usuários', 'value' => $calc($summaries, 'users'), 'format' => 'number', 'icon' => 'users', 'color' => 'indigo'],
            ['key' => 'pageviews', 'label' => 'Pageviews', 'value' => $calc($summaries, 'pageviews'), 'format' => 'number', 'icon' => 'eye', 'color' => 'purple'],
            ['key' => 'bounce_rate', 'label' => 'Taxa de Rejeição', 'value' => round($avg($summaries, 'bounce_rate'), 2), 'format' => 'percent', 'icon' => 'arrow-uturn-left', 'color' => 'red', 'inverse' => true],
            ['key' => 'avg_session_duration', 'label' => 'Duração Média', 'value' => round($avg($summaries, 'avg_session_duration'), 0), 'format' => 'duration', 'icon' => 'clock', 'color' => 'green'],
            ['key' => 'total_spend', 'label' => 'Investimento Total', 'value' => round($calc($summaries, 'total_spend'), 2), 'format' => 'currency', 'icon' => 'banknotes', 'color' => 'yellow'],
            ['key' => 'ad_spend', 'label' => 'Invest. API (Ads)', 'value' => round($calc($summaries, 'ad_spend'), 2), 'format' => 'currency', 'icon' => 'banknotes', 'color' => 'amber'],
            ['key' => 'manual_ad_spend', 'label' => 'Invest. Manual', 'value' => round($calc($summaries, 'manual_ad_spend'), 2), 'format' => 'currency', 'icon' => 'pencil-square', 'color' => 'orange'],
            ['key' => 'ad_clicks', 'label' => 'Cliques Ads', 'value' => $calc($summaries, 'ad_clicks'), 'format' => 'number', 'icon' => 'cursor-arrow-rays', 'color' => 'orange'],
            ['key' => 'ad_conversions', 'label' => 'Conversões', 'value' => $calc($summaries, 'ad_conversions'), 'format' => 'number', 'icon' => 'check-circle', 'color' => 'emerald'],
            ['key' => 'ad_roas', 'label' => 'ROAS Ads', 'value' => round($avg($summaries, 'ad_roas'), 2), 'format' => 'decimal', 'icon' => 'arrow-trending-up', 'color' => 'teal'],
            ['key' => 'wc_orders', 'label' => 'Pedidos', 'value' => $calc($summaries, 'wc_orders'), 'format' => 'number', 'icon' => 'shopping-bag', 'color' => 'violet'],
            ['key' => 'wc_revenue', 'label' => 'Receita Loja', 'value' => round($calc($summaries, 'wc_revenue'), 2), 'format' => 'currency', 'icon' => 'currency-dollar', 'color' => 'fuchsia'],
            ['key' => 'wc_avg_order_value', 'label' => 'Ticket Médio', 'value' => round($avg($summaries, 'wc_avg_order_value'), 2), 'format' => 'currency', 'icon' => 'receipt-percent', 'color' => 'pink'],
            ['key' => 'real_roas', 'label' => 'ROAS Real', 'value' => round($avg($summaries, 'real_roas'), 2), 'format' => 'decimal', 'icon' => 'arrow-trending-up', 'color' => 'rose'],
            ['key' => 'search_clicks', 'label' => 'Cliques SEO', 'value' => $calc($summaries, 'search_clicks'), 'format' => 'number', 'icon' => 'magnifying-glass', 'color' => 'lime'],
            ['key' => 'search_impressions', 'label' => 'Impressões SEO', 'value' => $calc($summaries, 'search_impressions'), 'format' => 'number', 'icon' => 'globe-alt', 'color' => 'cyan'],
            ['key' => 'search_position', 'label' => 'Posição Média', 'value' => round($avg($summaries, 'search_position'), 1), 'format' => 'decimal', 'icon' => 'list-bullet', 'color' => 'sky', 'inverse' => true],
        ];

        // Adicionar variação se tiver período de comparação
        if ($compareSummaries && $compareSummaries->isNotEmpty()) {
            foreach ($kpis as &$kpi) {
                $compareField = $kpi['key'];
                $isAvg = in_array($compareField, ['bounce_rate', 'avg_session_duration', 'ad_roas', 'real_roas', 'wc_avg_order_value', 'search_position', 'search_ctr']);
                $compareValue = $isAvg ? $avg($compareSummaries, $compareField) : $calc($compareSummaries, $compareField);

                if ($compareValue > 0) {
                    $variation = (($kpi['value'] - $compareValue) / $compareValue) * 100;
                    $kpi['variation'] = round($variation, 1);
                    $kpi['compareValue'] = $compareValue;
                } else {
                    $kpi['variation'] = $kpi['value'] > 0 ? 100 : 0;
                    $kpi['compareValue'] = 0;
                }
            }
        }

        return $kpis;
    }

    /**
     * Constrói dados para gráficos
     */
    protected function buildChartData($summaries): array
    {
        $dates = $summaries->pluck('date')->map(fn($d) => Carbon::parse($d)->format('d/m'))->values()->toArray();

        return [
            'dates' => $dates,
            'website' => [
                'sessions' => $summaries->pluck('sessions')->toArray(),
                'users' => $summaries->pluck('users')->toArray(),
                'pageviews' => $summaries->pluck('pageviews')->toArray(),
                'bounce_rate' => $summaries->pluck('bounce_rate')->toArray(),
            ],
            'ads' => [
                'spend' => $summaries->pluck('ad_spend')->toArray(),
                'total_spend' => $summaries->pluck('total_spend')->toArray(),
                'manual_spend' => $summaries->pluck('manual_ad_spend')->toArray(),
                'clicks' => $summaries->pluck('ad_clicks')->toArray(),
                'impressions' => $summaries->pluck('ad_impressions')->toArray(),
                'conversions' => $summaries->pluck('ad_conversions')->toArray(),
                'roas' => $summaries->pluck('ad_roas')->toArray(),
            ],
            'ecommerce' => [
                'orders' => $summaries->pluck('wc_orders')->toArray(),
                'revenue' => $summaries->pluck('wc_revenue')->toArray(),
                'avg_order_value' => $summaries->pluck('wc_avg_order_value')->toArray(),
                'items_sold' => $summaries->pluck('wc_items_sold')->toArray(),
                'refunds' => $summaries->pluck('wc_refunds')->toArray(),
                'real_roas' => $summaries->pluck('real_roas')->toArray(),
            ],
            'seo' => [
                'clicks' => $summaries->pluck('search_clicks')->toArray(),
                'impressions' => $summaries->pluck('search_impressions')->toArray(),
                'position' => $summaries->pluck('search_position')->toArray(),
                'ctr' => $summaries->pluck('search_ctr')->toArray(),
            ],
        ];
    }

    /**
     * Busca top dimensões (sources, pages, queries, devices)
     */
    protected function getTopDimensions(?int $brandId, string $startDate, string $endDate): array
    {
        $endDate = Carbon::parse($endDate)->format('Y-m-d');

        $scope = fn($query) => $brandId ? $query->where('brand_id', $brandId) : $query;

        return [
            'sources' => $scope(AnalyticsDataPoint::query())
                ->where('platform', 'google_analytics')
                ->where('dimension_key', 'source')
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(10)
                ->get(['dimension_value as name', 'value', 'extra'])
                ->toArray(),
            'mediums' => $scope(AnalyticsDataPoint::query())
                ->where('platform', 'google_analytics')
                ->where('dimension_key', 'medium')
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(10)
                ->get(['dimension_value as name', 'value', 'extra'])
                ->toArray(),
            'pages' => $scope(AnalyticsDataPoint::query())
                ->where('platform', 'google_analytics')
                ->where('dimension_key', 'page')
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(10)
                ->get(['dimension_value as name', 'value', 'extra'])
                ->toArray(),
            'devices' => $scope(AnalyticsDataPoint::query())
                ->where('platform', 'google_analytics')
                ->where('dimension_key', 'device')
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(10)
                ->get(['dimension_value as name', 'value', 'extra'])
                ->toArray(),
            'queries' => $scope(AnalyticsDataPoint::query())
                ->where('platform', 'google_search_console')
                ->where('dimension_key', 'query')
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(15)
                ->get(['dimension_value as name', 'value', 'extra'])
                ->toArray(),
            'campaigns' => $scope(AnalyticsDataPoint::query())
                ->whereIn('platform', ['google_ads', 'meta_ads', 'google_analytics'])
                ->where('dimension_key', 'campaign')
                ->whereIn('metric_key', ['spend', 'ga4_ad_cost'])
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(10)
                ->get(['dimension_value as name', 'value', 'platform', 'extra'])
                ->toArray(),
            'products' => $scope(AnalyticsDataPoint::query())
                ->where('platform', 'woocommerce')
                ->where('dimension_key', 'product')
                ->where('metric_key', 'wc_product_revenue')
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(10)
                ->get(['dimension_value as name', 'value', 'extra'])
                ->toArray(),
        ];
    }
}

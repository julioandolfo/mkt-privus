<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsConnection;
use App\Models\AnalyticsDailySummary;
use App\Models\AnalyticsDataPoint;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AnalyticsSyncService
{
    protected GoogleAnalyticsService $gaService;
    protected MetaAdsService $metaAdsService;
    protected GoogleSearchConsoleService $gscService;
    protected GoogleAdsService $googleAdsService;

    public function __construct(
        GoogleAnalyticsService $gaService,
        MetaAdsService $metaAdsService,
        GoogleSearchConsoleService $gscService,
        GoogleAdsService $googleAdsService
    ) {
        $this->gaService = $gaService;
        $this->metaAdsService = $metaAdsService;
        $this->gscService = $gscService;
        $this->googleAdsService = $googleAdsService;
    }

    /**
     * Sincroniza todas as conexões ativas de uma marca
     */
    public function syncBrand(int $brandId, ?string $startDate = null, ?string $endDate = null): array
    {
        $connections = AnalyticsConnection::where('brand_id', $brandId)
            ->where('is_active', true)
            ->get();

        $results = [];

        foreach ($connections as $connection) {
            $results[$connection->platform] = $this->syncConnection($connection, $startDate, $endDate);
        }

        // Recalcular sumários diários
        $this->rebuildDailySummaries($brandId, $startDate ?? now()->subDays(30)->format('Y-m-d'), $endDate ?? now()->format('Y-m-d'));

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
            default => ['success' => false, 'error' => "Plataforma {$connection->platform} não suportada"],
        };
    }

    /**
     * Reconstrói os sumários diários para uma marca num período
     */
    public function rebuildDailySummaries(int $brandId, string $startDate, string $endDate): void
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateStr = $date->format('Y-m-d');

            $gaData = AnalyticsDataPoint::where('brand_id', $brandId)
                ->where('platform', 'google_analytics')
                ->where('date', $dateStr)
                ->whereNull('dimension_key')
                ->get()
                ->keyBy('metric_key');

            $adData = AnalyticsDataPoint::where('brand_id', $brandId)
                ->whereIn('platform', ['meta_ads', 'google_ads'])
                ->where('date', $dateStr)
                ->whereNull('dimension_key')
                ->get();

            $scData = AnalyticsDataPoint::where('brand_id', $brandId)
                ->where('platform', 'google_search_console')
                ->where('date', $dateStr)
                ->whereNull('dimension_key')
                ->get()
                ->keyBy('metric_key');

            // Agregar dados de ads (soma de todas as fontes)
            $totalSpend = $adData->where('metric_key', 'spend')->sum('value');
            $totalImpressions = $adData->where('metric_key', 'impressions')->sum('value');
            $totalClicks = $adData->where('metric_key', 'clicks')->sum('value');
            $totalConversions = $adData->where('metric_key', 'conversions')->sum('value');
            $totalRevenue = $adData->where('metric_key', 'revenue')->sum('value') + $adData->where('metric_key', 'conversion_value')->sum('value');

            $adCtr = $totalImpressions > 0 ? ($totalClicks / $totalImpressions) * 100 : 0;
            $adCpc = $totalClicks > 0 ? $totalSpend / $totalClicks : 0;
            $adRoas = $totalSpend > 0 ? $totalRevenue / $totalSpend : 0;

            AnalyticsDailySummary::updateOrCreate(
                ['brand_id' => $brandId, 'date' => $dateStr],
                [
                    'sessions' => intval($gaData->get('sessions')?->value ?? 0),
                    'users' => intval($gaData->get('users')?->value ?? 0),
                    'new_users' => intval($gaData->get('new_users')?->value ?? 0),
                    'pageviews' => intval($gaData->get('pageviews')?->value ?? 0),
                    'bounce_rate' => floatval($gaData->get('bounce_rate')?->value ?? 0),
                    'avg_session_duration' => floatval($gaData->get('avg_session_duration')?->value ?? 0),
                    'ad_spend' => $totalSpend,
                    'ad_impressions' => intval($totalImpressions),
                    'ad_clicks' => intval($totalClicks),
                    'ad_conversions' => intval($totalConversions),
                    'ad_revenue' => $totalRevenue,
                    'ad_ctr' => $adCtr,
                    'ad_cpc' => $adCpc,
                    'ad_roas' => $adRoas,
                    'search_impressions' => intval($scData->get('search_impressions')?->value ?? 0),
                    'search_clicks' => intval($scData->get('search_clicks')?->value ?? 0),
                    'search_ctr' => floatval($scData->get('search_ctr')?->value ?? 0),
                    'search_position' => floatval($scData->get('search_position')?->value ?? 0),
                ]
            );
        }
    }

    /**
     * Retorna dados do dashboard para uma marca
     */
    public function getDashboardData(int $brandId, string $startDate, string $endDate, ?string $compareStartDate = null, ?string $compareEndDate = null): array
    {
        // Período principal
        $summaries = AnalyticsDailySummary::where('brand_id', $brandId)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get();

        // Período de comparação
        $compareSummaries = null;
        if ($compareStartDate && $compareEndDate) {
            $compareSummaries = AnalyticsDailySummary::where('brand_id', $brandId)
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
        $connections = AnalyticsConnection::where('brand_id', $brandId)
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
            ['key' => 'ad_spend', 'label' => 'Investimento Ads', 'value' => round($calc($summaries, 'ad_spend'), 2), 'format' => 'currency', 'icon' => 'banknotes', 'color' => 'yellow'],
            ['key' => 'ad_clicks', 'label' => 'Cliques Ads', 'value' => $calc($summaries, 'ad_clicks'), 'format' => 'number', 'icon' => 'cursor-arrow-rays', 'color' => 'orange'],
            ['key' => 'ad_conversions', 'label' => 'Conversões', 'value' => $calc($summaries, 'ad_conversions'), 'format' => 'number', 'icon' => 'check-circle', 'color' => 'emerald'],
            ['key' => 'ad_roas', 'label' => 'ROAS', 'value' => round($avg($summaries, 'ad_roas'), 2), 'format' => 'decimal', 'icon' => 'arrow-trending-up', 'color' => 'teal'],
            ['key' => 'search_clicks', 'label' => 'Cliques SEO', 'value' => $calc($summaries, 'search_clicks'), 'format' => 'number', 'icon' => 'magnifying-glass', 'color' => 'lime'],
            ['key' => 'search_impressions', 'label' => 'Impressões SEO', 'value' => $calc($summaries, 'search_impressions'), 'format' => 'number', 'icon' => 'globe-alt', 'color' => 'cyan'],
            ['key' => 'search_position', 'label' => 'Posição Média', 'value' => round($avg($summaries, 'search_position'), 1), 'format' => 'decimal', 'icon' => 'list-bullet', 'color' => 'sky', 'inverse' => true],
        ];

        // Adicionar variação se tiver período de comparação
        if ($compareSummaries && $compareSummaries->isNotEmpty()) {
            foreach ($kpis as &$kpi) {
                $compareField = $kpi['key'];
                $isAvg = in_array($compareField, ['bounce_rate', 'avg_session_duration', 'ad_roas', 'search_position', 'search_ctr']);
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
                'clicks' => $summaries->pluck('ad_clicks')->toArray(),
                'impressions' => $summaries->pluck('ad_impressions')->toArray(),
                'conversions' => $summaries->pluck('ad_conversions')->toArray(),
                'roas' => $summaries->pluck('ad_roas')->toArray(),
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
    protected function getTopDimensions(int $brandId, string $startDate, string $endDate): array
    {
        $endDate = Carbon::parse($endDate)->format('Y-m-d');

        return [
            'sources' => AnalyticsDataPoint::where('brand_id', $brandId)
                ->where('platform', 'google_analytics')
                ->where('dimension_key', 'source')
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(10)
                ->get(['dimension_value as name', 'value', 'extra'])
                ->toArray(),
            'mediums' => AnalyticsDataPoint::where('brand_id', $brandId)
                ->where('platform', 'google_analytics')
                ->where('dimension_key', 'medium')
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(10)
                ->get(['dimension_value as name', 'value', 'extra'])
                ->toArray(),
            'pages' => AnalyticsDataPoint::where('brand_id', $brandId)
                ->where('platform', 'google_analytics')
                ->where('dimension_key', 'page')
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(10)
                ->get(['dimension_value as name', 'value', 'extra'])
                ->toArray(),
            'devices' => AnalyticsDataPoint::where('brand_id', $brandId)
                ->where('platform', 'google_analytics')
                ->where('dimension_key', 'device')
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(10)
                ->get(['dimension_value as name', 'value', 'extra'])
                ->toArray(),
            'queries' => AnalyticsDataPoint::where('brand_id', $brandId)
                ->where('platform', 'google_search_console')
                ->where('dimension_key', 'query')
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(15)
                ->get(['dimension_value as name', 'value', 'extra'])
                ->toArray(),
            'campaigns' => AnalyticsDataPoint::where('brand_id', $brandId)
                ->whereIn('platform', ['google_ads', 'meta_ads'])
                ->where('dimension_key', 'campaign')
                ->where('metric_key', 'spend')
                ->where('date', $endDate)
                ->orderByDesc('value')
                ->limit(10)
                ->get(['dimension_value as name', 'value', 'platform', 'extra'])
                ->toArray(),
        ];
    }
}

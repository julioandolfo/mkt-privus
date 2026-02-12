<?php

namespace App\Services\Social;

use App\Models\AnalyticsDailySummary;
use App\Models\AnalyticsDataPoint;
use App\Models\Brand;
use App\Models\ContentCalendarItem;
use App\Models\ContentSuggestion;
use App\Models\SocialInsight;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Servico de inteligencia de marca.
 * Compila um relatorio completo com dados de redes sociais, analytics,
 * e-commerce e historico de conteudo para alimentar a IA na geracao
 * de calendarios e pautas mais inteligentes.
 */
class BrandIntelligenceService
{
    /**
     * Gera relatorio de inteligencia completo para a marca.
     * Retorna texto estruturado para injecao no prompt da IA.
     */
    public function buildIntelligenceReport(Brand $brand): string
    {
        $sections = [];

        // 1. Dados de Redes Sociais
        $socialReport = $this->buildSocialReport($brand);
        if ($socialReport) {
            $sections[] = $socialReport;
        }

        // 2. Dados de Analytics (website + SEO)
        $analyticsReport = $this->buildAnalyticsReport($brand);
        if ($analyticsReport) {
            $sections[] = $analyticsReport;
        }

        // 3. Dados de E-commerce
        $ecommerceReport = $this->buildEcommerceReport($brand);
        if ($ecommerceReport) {
            $sections[] = $ecommerceReport;
        }

        // 4. Historico de conteudo e aprovacoes
        $contentReport = $this->buildContentHistoryReport($brand);
        if ($contentReport) {
            $sections[] = $contentReport;
        }

        if (empty($sections)) {
            return '';
        }

        return "## DADOS DE PERFORMANCE E INTELIGENCIA (ultimos 30 dias)\n\n" . implode("\n\n", $sections);
    }

    // ===================================================================
    // REDES SOCIAIS
    // ===================================================================

    protected function buildSocialReport(Brand $brand): ?string
    {
        $insights = SocialInsight::where('brand_id', $brand->id)
            ->where('date', '>=', now()->subDays(30))
            ->orderBy('date')
            ->get();

        if ($insights->isEmpty()) {
            return null;
        }

        $lines = ["### Redes Sociais"];

        // Engagement medio por plataforma
        $byPlatform = $insights->groupBy(fn($i) => $i->socialAccount?->platform?->value ?? 'unknown');
        foreach ($byPlatform as $platform => $platformInsights) {
            if ($platform === 'unknown') continue;

            $avgEngagement = $platformInsights->avg('engagement_rate');
            $avgReach = $platformInsights->avg('reach');
            $avgLikes = $platformInsights->avg('likes');
            $avgComments = $platformInsights->avg('comments');
            $latestFollowers = $platformInsights->last()?->followers_count ?? 0;
            $firstFollowers = $platformInsights->first()?->followers_count ?? 0;
            $followerGrowth = $latestFollowers - $firstFollowers;

            $lines[] = "- {$platform}: {$latestFollowers} seguidores"
                . ($followerGrowth != 0 ? " (" . ($followerGrowth > 0 ? '+' : '') . "{$followerGrowth} no periodo)" : '')
                . ", engajamento medio " . number_format($avgEngagement, 2) . "%"
                . ", alcance medio " . number_format($avgReach, 0, ',', '.') . "/dia"
                . ", media de " . number_format($avgLikes, 0) . " curtidas e " . number_format($avgComments, 0) . " comentarios/dia";
        }

        // Melhores dias da semana (por engajamento total)
        $byDayOfWeek = $insights->groupBy(fn($i) => $i->date->dayOfWeek);
        $dayNames = ['Domingo', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'];
        $dayEngagement = [];
        foreach ($byDayOfWeek as $dow => $dayInsights) {
            $dayEngagement[$dow] = $dayInsights->avg('engagement');
        }
        arsort($dayEngagement);
        $bestDays = array_slice(array_keys($dayEngagement), 0, 3);
        $bestDayNames = array_map(fn($d) => $dayNames[$d] ?? $d, $bestDays);
        $lines[] = "- Melhores dias para engajamento: " . implode(', ', $bestDayNames);

        // Tipo de conteudo com melhor performance
        $reelViews = $insights->sum('reel_views');
        $videoViews = $insights->sum('video_views');
        $storyViews = $insights->sum('story_views');
        $totalReach = $insights->sum('reach');
        $contentPerformance = [];
        if ($reelViews > 0) $contentPerformance['Reels'] = $reelViews;
        if ($videoViews > 0) $contentPerformance['Videos'] = $videoViews;
        if ($storyViews > 0) $contentPerformance['Stories'] = $storyViews;
        if ($totalReach > 0) $contentPerformance['Feed (alcance)'] = $totalReach;
        arsort($contentPerformance);
        if (!empty($contentPerformance)) {
            $top = array_slice($contentPerformance, 0, 3, true);
            $parts = [];
            foreach ($top as $type => $val) {
                $parts[] = "{$type}: " . number_format($val, 0, ',', '.');
            }
            $lines[] = "- Performance por tipo: " . implode(' | ', $parts);
        }

        // Demografia
        $latestWithDemographics = $insights->filter(fn($i) => !empty($i->audience_age))->last();
        if ($latestWithDemographics) {
            if (!empty($latestWithDemographics->audience_age)) {
                $ages = collect($latestWithDemographics->audience_age)->sortDesc()->take(3);
                $ageStr = $ages->map(fn($pct, $range) => "{$range}: {$pct}%")->implode(', ');
                $lines[] = "- Faixa etaria do publico: {$ageStr}";
            }
            if (!empty($latestWithDemographics->audience_gender)) {
                $gender = $latestWithDemographics->audience_gender;
                $genderParts = [];
                if (isset($gender['female'])) $genderParts[] = "Feminino {$gender['female']}%";
                if (isset($gender['male'])) $genderParts[] = "Masculino {$gender['male']}%";
                if (!empty($genderParts)) {
                    $lines[] = "- Genero do publico: " . implode(', ', $genderParts);
                }
            }
            if (!empty($latestWithDemographics->audience_cities)) {
                $cities = collect($latestWithDemographics->audience_cities)->sortDesc()->take(5);
                $cityStr = $cities->map(fn($pct, $city) => "{$city} ({$pct}%)")->implode(', ');
                $lines[] = "- Principais cidades: {$cityStr}";
            }
        }

        return implode("\n", $lines);
    }

    // ===================================================================
    // ANALYTICS (Website + SEO)
    // ===================================================================

    protected function buildAnalyticsReport(Brand $brand): ?string
    {
        $summaries = AnalyticsDailySummary::where('brand_id', $brand->id)
            ->where('date', '>=', now()->subDays(30))
            ->orderBy('date')
            ->get();

        if ($summaries->isEmpty()) {
            return null;
        }

        $lines = ["### Website e SEO"];

        // Metricas de trafego
        $totalSessions = $summaries->sum('sessions');
        $totalUsers = $summaries->sum('users');
        $avgBounce = $summaries->avg('bounce_rate');
        $avgDuration = $summaries->avg('avg_session_duration');

        if ($totalSessions > 0) {
            $lines[] = "- Trafego (30 dias): " . number_format($totalSessions, 0, ',', '.') . " sessoes"
                . ", " . number_format($totalUsers, 0, ',', '.') . " usuarios"
                . ", bounce rate " . number_format($avgBounce, 1) . "%"
                . ", duracao media " . number_format($avgDuration, 0) . "s";
        }

        // Tendencia de trafego (comparar primeira e segunda metade do periodo)
        $half = intval(ceil($summaries->count() / 2));
        $firstHalf = $summaries->take($half);
        $secondHalf = $summaries->skip($half);
        $firstAvgSessions = $firstHalf->avg('sessions') ?: 0;
        $secondAvgSessions = $secondHalf->avg('sessions') ?: 0;
        if ($firstAvgSessions > 0) {
            $trend = (($secondAvgSessions - $firstAvgSessions) / $firstAvgSessions) * 100;
            $trendLabel = $trend > 5 ? 'CRESCENDO' : ($trend < -5 ? 'CAINDO' : 'ESTAVEL');
            $lines[] = "- Tendencia de trafego: {$trendLabel} (" . number_format($trend, 1) . "% na segunda quinzena)";
        }

        // Top paginas mais acessadas (via AnalyticsDataPoint)
        $topPages = AnalyticsDataPoint::where('brand_id', $brand->id)
            ->where('platform', 'google_analytics')
            ->where('metric_key', 'pageviews')
            ->where('dimension_key', 'page')
            ->where('date', '>=', now()->subDays(30))
            ->selectRaw('dimension_value, SUM(value) as total')
            ->groupBy('dimension_value')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        if ($topPages->isNotEmpty()) {
            $lines[] = "- Top paginas acessadas:";
            foreach ($topPages as $page) {
                $lines[] = "  - {$page->dimension_value}: " . number_format($page->total, 0, ',', '.') . " views";
            }
        }

        // Top termos de busca (SEO via GSC)
        $topQueries = AnalyticsDataPoint::where('brand_id', $brand->id)
            ->where('platform', 'google_search_console')
            ->where('metric_key', 'search_clicks')
            ->where('dimension_key', 'query')
            ->where('date', '>=', now()->subDays(30))
            ->selectRaw('dimension_value, SUM(value) as total')
            ->groupBy('dimension_value')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        if ($topQueries->isNotEmpty()) {
            $lines[] = "- Top termos de busca organica (Google):";
            foreach ($topQueries as $query) {
                $lines[] = "  - \"{$query->dimension_value}\": " . number_format($query->total, 0) . " cliques";
            }
        }

        // Fontes de trafego
        $topSources = AnalyticsDataPoint::where('brand_id', $brand->id)
            ->where('platform', 'google_analytics')
            ->where('metric_key', 'sessions')
            ->where('dimension_key', 'source')
            ->where('date', '>=', now()->subDays(30))
            ->selectRaw('dimension_value, SUM(value) as total')
            ->groupBy('dimension_value')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        if ($topSources->isNotEmpty()) {
            $sourceParts = $topSources->map(fn($s) => "{$s->dimension_value}: " . number_format($s->total, 0, ',', '.'))->implode(', ');
            $lines[] = "- Fontes de trafego: {$sourceParts}";
        }

        // Dados de Ads (se disponivel)
        $totalAdSpend = $summaries->sum('ad_spend') + $summaries->sum('manual_ad_spend');
        $totalAdClicks = $summaries->sum('ad_clicks');
        $totalAdConversions = $summaries->sum('ad_conversions');
        if ($totalAdSpend > 0) {
            $lines[] = "- Anuncios (30 dias): R$ " . number_format($totalAdSpend, 2, ',', '.') . " investidos"
                . ", " . number_format($totalAdClicks, 0, ',', '.') . " cliques"
                . ", " . number_format($totalAdConversions, 0) . " conversoes";
        }

        return count($lines) > 1 ? implode("\n", $lines) : null;
    }

    // ===================================================================
    // E-COMMERCE
    // ===================================================================

    protected function buildEcommerceReport(Brand $brand): ?string
    {
        $summaries = AnalyticsDailySummary::where('brand_id', $brand->id)
            ->where('date', '>=', now()->subDays(30))
            ->where('wc_revenue', '>', 0)
            ->get();

        if ($summaries->isEmpty()) {
            return null;
        }

        $lines = ["### E-commerce"];

        $totalRevenue = $summaries->sum('wc_revenue');
        $totalOrders = $summaries->sum('wc_orders');
        $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
        $totalItems = $summaries->sum('wc_items_sold');
        $totalRefunds = $summaries->sum('wc_refunds');
        $totalNewCustomers = $summaries->sum('wc_new_customers');

        $lines[] = "- Receita (30 dias): R$ " . number_format($totalRevenue, 2, ',', '.')
            . " em " . number_format($totalOrders, 0) . " pedidos"
            . " (ticket medio: R$ " . number_format($avgOrderValue, 2, ',', '.') . ")"
            . ", " . number_format($totalItems, 0) . " itens vendidos";

        if ($totalNewCustomers > 0) {
            $lines[] = "- Novos clientes: {$totalNewCustomers}";
        }

        if ($totalRefunds > 0) {
            $refundRate = $totalRevenue > 0 ? ($totalRefunds / $totalRevenue) * 100 : 0;
            $lines[] = "- Reembolsos: R$ " . number_format($totalRefunds, 2, ',', '.') . " (" . number_format($refundRate, 1) . "%)";
        }

        // ROAS real
        $totalSpend = $summaries->sum('total_spend');
        if ($totalSpend > 0 && $totalRevenue > 0) {
            $realRoas = $totalRevenue / $totalSpend;
            $lines[] = "- ROAS real: " . number_format($realRoas, 2) . "x (receita / investimento total)";
        }

        // Tendencia de receita
        $half = intval(ceil($summaries->count() / 2));
        $firstHalf = $summaries->take($half);
        $secondHalf = $summaries->skip($half);
        $firstAvgRevenue = $firstHalf->avg('wc_revenue') ?: 0;
        $secondAvgRevenue = $secondHalf->avg('wc_revenue') ?: 0;
        if ($firstAvgRevenue > 0) {
            $trend = (($secondAvgRevenue - $firstAvgRevenue) / $firstAvgRevenue) * 100;
            $trendLabel = $trend > 5 ? 'CRESCENDO' : ($trend < -5 ? 'CAINDO' : 'ESTAVEL');
            $lines[] = "- Tendencia de receita: {$trendLabel} (" . number_format($trend, 1) . "%)";
        }

        // Top produtos vendidos
        $topProducts = AnalyticsDataPoint::where('brand_id', $brand->id)
            ->where('platform', 'woocommerce')
            ->where('metric_key', 'wc_product_revenue')
            ->where('dimension_key', 'product')
            ->where('date', '>=', now()->subDays(30))
            ->selectRaw('dimension_value, SUM(value) as total')
            ->groupBy('dimension_value')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        if ($topProducts->isNotEmpty()) {
            $lines[] = "- Top produtos vendidos:";
            foreach ($topProducts as $product) {
                $lines[] = "  - {$product->dimension_value}: R$ " . number_format($product->total, 2, ',', '.');
            }
        }

        // Melhores dias de venda
        $byDow = $summaries->groupBy(fn($s) => $s->date->dayOfWeek);
        $dayNames = ['Domingo', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'];
        $dayRevenue = [];
        foreach ($byDow as $dow => $daySummaries) {
            $dayRevenue[$dow] = $daySummaries->avg('wc_revenue');
        }
        arsort($dayRevenue);
        $bestSalesDays = array_slice(array_keys($dayRevenue), 0, 3);
        $bestSalesDayNames = array_map(fn($d) => $dayNames[$d] ?? $d, $bestSalesDays);
        $lines[] = "- Melhores dias para vendas: " . implode(', ', $bestSalesDayNames);

        return implode("\n", $lines);
    }

    // ===================================================================
    // HISTORICO DE CONTEUDO
    // ===================================================================

    protected function buildContentHistoryReport(Brand $brand): ?string
    {
        // Sugestoes dos ultimos 90 dias (janela maior para historico)
        $suggestions = ContentSuggestion::where('brand_id', $brand->id)
            ->where('created_at', '>=', now()->subDays(90))
            ->get();

        $calendarItems = ContentCalendarItem::where('brand_id', $brand->id)
            ->where('created_at', '>=', now()->subDays(90))
            ->get();

        if ($suggestions->isEmpty() && $calendarItems->isEmpty()) {
            return null;
        }

        $lines = ["### Historico de Conteudo (ultimos 90 dias)"];

        // Taxa de aprovacao geral
        $totalSuggestions = $suggestions->count();
        if ($totalSuggestions > 0) {
            $approved = $suggestions->whereIn('status', ['approved', 'converted'])->count();
            $rejected = $suggestions->where('status', 'rejected')->count();
            $pending = $suggestions->where('status', 'pending')->count();
            $approvalRate = ($approved / $totalSuggestions) * 100;

            $lines[] = "- Sugestoes de post: {$totalSuggestions} total"
                . ", {$approved} aprovadas (" . number_format($approvalRate, 0) . "%)"
                . ", {$rejected} rejeitadas, {$pending} pendentes";
        }

        // Taxa de aprovacao por categoria (via calendar items que geraram suggestions)
        $itemsWithCategory = $calendarItems->filter(fn($i) => !empty($i->category));
        if ($itemsWithCategory->isNotEmpty()) {
            $byCategory = $itemsWithCategory->groupBy('category');
            $categoryStats = [];
            foreach ($byCategory as $cat => $items) {
                $total = $items->count();
                $generated = $items->whereIn('status', ['generated', 'approved', 'published'])->count();
                $skipped = $items->where('status', 'skipped')->count();
                $categoryStats[$cat] = [
                    'total' => $total,
                    'used' => $generated,
                    'skipped' => $skipped,
                    'rate' => $total > 0 ? round(($generated / $total) * 100) : 0,
                ];
            }

            // Ordenar por taxa de aprovacao
            uasort($categoryStats, fn($a, $b) => $b['rate'] <=> $a['rate']);

            $bestCategories = array_slice(array_keys($categoryStats), 0, 5);
            $worstCategories = array_slice(array_keys(array_reverse($categoryStats, true)), 0, 3);

            $lines[] = "- Categorias mais aceitas: " . implode(', ', array_map(fn($c) => "{$c} ({$categoryStats[$c]['rate']}%)", $bestCategories));

            $lowPerformers = array_filter($categoryStats, fn($s) => $s['rate'] < 50 && $s['total'] >= 3);
            if (!empty($lowPerformers)) {
                $lines[] = "- Categorias menos aceitas: " . implode(', ', array_map(fn($c) => "{$c} ({$categoryStats[$c]['rate']}%)", array_keys($lowPerformers)));
            }
        }

        // Plataformas mais usadas em aprovacoes
        $approvedSuggestions = $suggestions->whereIn('status', ['approved', 'converted']);
        if ($approvedSuggestions->isNotEmpty()) {
            $platformCounts = [];
            foreach ($approvedSuggestions as $s) {
                foreach (($s->platforms ?? []) as $p) {
                    $platformCounts[$p] = ($platformCounts[$p] ?? 0) + 1;
                }
            }
            arsort($platformCounts);
            $platformStr = collect($platformCounts)->map(fn($count, $p) => "{$p}: {$count}")->implode(', ');
            $lines[] = "- Plataformas preferidas (aprovacoes): {$platformStr}";
        }

        // Tipos de post mais aprovados
        $postTypeCounts = $approvedSuggestions->groupBy('post_type')->map->count()->sortDesc();
        if ($postTypeCounts->isNotEmpty()) {
            $typeStr = $postTypeCounts->map(fn($count, $type) => "{$type}: {$count}")->implode(', ');
            $lines[] = "- Tipos de post preferidos: {$typeStr}";
        }

        // Motivos de rejeicao mais comuns
        $rejections = $suggestions->where('status', 'rejected')->filter(fn($s) => !empty($s->rejection_reason));
        if ($rejections->isNotEmpty()) {
            $reasons = $rejections->pluck('rejection_reason')->take(5)->implode('; ');
            $lines[] = "- Motivos de rejeicao recentes: {$reasons}";
        }

        return count($lines) > 1 ? implode("\n", $lines) : null;
    }
}

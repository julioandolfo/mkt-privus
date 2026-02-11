<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsConnection;
use App\Models\AnalyticsDailySummary;
use App\Models\CustomMetric;
use App\Models\ManualAdEntry;
use App\Models\MetricGoal;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $brand = $user->getActiveBrand();
        $brandId = $brand?->id;

        // ===== STATS BASICOS =====
        $stats = [
            'posts_this_month' => $brand ? $brand->posts()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count() : 0,
            'scheduled_posts' => $brand ? $brand->posts()
                ->where('status', 'scheduled')
                ->count() : 0,
            'published_posts' => $brand ? $brand->posts()
                ->where('status', 'published')
                ->count() : 0,
            'connected_platforms' => $brand ? $brand->socialAccounts()
                ->where('is_active', true)
                ->count() : 0,
        ];

        // ===== CONTAS SOCIAIS COM INSIGHTS =====
        $socialAccounts = [];
        $totalFollowers = 0;
        $totalFollowersYesterday = 0;

        if ($brandId) {
            $accounts = SocialAccount::where('brand_id', $brandId)
                ->where('is_active', true)
                ->get();

            foreach ($accounts as $account) {
                $latest = SocialInsight::where('social_account_id', $account->id)
                    ->where('sync_status', 'success')
                    ->orderByDesc('date')
                    ->first();

                $previous = null;
                if ($latest) {
                    $previous = SocialInsight::where('social_account_id', $account->id)
                        ->where('sync_status', 'success')
                        ->where('date', '<', $latest->date)
                        ->orderByDesc('date')
                        ->first();
                }

                $followersCount = $latest?->followers_count ?? ($account->metadata['followers_count'] ?? $account->metadata['fan_count'] ?? $account->metadata['subscriber_count'] ?? null);
                $previousFollowers = $previous?->followers_count ?? null;

                if ($followersCount) {
                    $totalFollowers += $followersCount;
                }
                if ($previousFollowers) {
                    $totalFollowersYesterday += $previousFollowers;
                }

                $socialAccounts[] = [
                    'id' => $account->id,
                    'platform' => $account->platform->value ?? $account->platform,
                    'display_name' => $account->display_name,
                    'username' => $account->username,
                    'avatar_url' => $account->avatar_url,
                    'followers_count' => $followersCount,
                    'followers_variation' => ($previousFollowers && $previousFollowers > 0)
                        ? round((($followersCount - $previousFollowers) / $previousFollowers) * 100, 1)
                        : null,
                    'net_followers' => $latest?->net_followers,
                    'engagement' => $latest?->engagement,
                    'engagement_rate' => $latest?->engagement_rate,
                    'reach' => $latest?->reach,
                    'impressions' => $latest?->impressions,
                    'likes' => $latest?->likes,
                    'comments' => $latest?->comments,
                    'saves' => $latest?->saves,
                    'last_sync' => $latest?->date?->format('d/m/Y'),
                ];
            }
        }

        // Resumo social
        $socialSummary = [
            'total_followers' => $totalFollowers,
            'followers_growth' => ($totalFollowersYesterday > 0)
                ? round((($totalFollowers - $totalFollowersYesterday) / $totalFollowersYesterday) * 100, 2)
                : null,
            'total_accounts' => count($socialAccounts),
        ];

        // ===== METRICAS COM DADOS RECENTES =====
        $metrics = [];
        if ($brandId) {
            $metrics = CustomMetric::where('brand_id', $brandId)
                ->where('is_active', true)
                ->with('metricCategory')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit(12)
                ->get()
                ->map(function ($metric) {
                    $latestEntry = $metric->entries()->latest('date')->first();
                    $previousEntry = $metric->entries()
                        ->where('date', '<', $latestEntry?->date ?? now())
                        ->latest('date')
                        ->first();

                    $variation = null;
                    if ($latestEntry && $previousEntry && $previousEntry->value > 0) {
                        $variation = round((($latestEntry->value - $previousEntry->value) / abs($previousEntry->value)) * 100, 1);
                    }

                    $activeGoal = $metric->activeGoals()->where('end_date', '>=', now())->orderBy('end_date')->first();
                    $goalProgress = $activeGoal ? $activeGoal->calculateProgress() : $metric->getGoalProgress();

                    // Ultimos 30 dias de entries para sparkline
                    $sparkline = $metric->entries()
                        ->where('date', '>=', now()->subDays(30))
                        ->orderBy('date')
                        ->pluck('value')
                        ->map(fn($v) => (float) $v)
                        ->toArray();

                    return [
                        'id' => $metric->id,
                        'name' => $metric->name,
                        'description' => $metric->description,
                        'category' => $metric->metricCategory?->name ?? $metric->category,
                        'unit' => $metric->unit,
                        'color' => $metric->color,
                        'icon' => $metric->icon,
                        'direction' => $metric->direction ?? 'up',
                        'platform' => $metric->platform,
                        'auto_sync' => $metric->auto_sync,
                        'latest_value' => $latestEntry ? (float) $latestEntry->value : null,
                        'latest_date' => $latestEntry?->date?->format('d/m/Y'),
                        'formatted_value' => $latestEntry ? $metric->formatValue((float) $latestEntry->value) : '--',
                        'variation' => $variation,
                        'variation_positive' => $metric->isVariationPositive($variation),
                        'goal_value' => $activeGoal ? (float) $activeGoal->target_value : (float) $metric->goal_value,
                        'goal_progress' => $goalProgress !== null ? round($goalProgress, 1) : null,
                        'goal_name' => $activeGoal?->name,
                        'sparkline' => $sparkline,
                    ];
                })
                ->toArray();
        }

        // ===== METAS ATIVAS =====
        $activeGoals = [];
        if ($brandId) {
            $activeGoals = MetricGoal::whereHas('metric', function ($q) use ($brandId) {
                    $q->where('brand_id', $brandId)->where('is_active', true);
                })
                ->where('is_active', true)
                ->where('end_date', '>=', now())
                ->with('metric')
                ->orderBy('end_date')
                ->limit(8)
                ->get()
                ->map(function ($goal) {
                    $progress = $goal->calculateProgress();
                    return [
                        'id' => $goal->id,
                        'name' => $goal->name,
                        'metric_name' => $goal->metric->name,
                        'metric_color' => $goal->metric->color,
                        'target_value' => (float) $goal->target_value,
                        'target_formatted' => $goal->metric->formatValue((float) $goal->target_value),
                        'current_value' => $goal->metric->getLatestValue(),
                        'current_formatted' => $goal->metric->getLatestValue() !== null
                            ? $goal->metric->formatValue($goal->metric->getLatestValue())
                            : '--',
                        'progress' => $progress !== null ? round($progress, 1) : 0,
                        'period' => $goal->period,
                        'start_date' => $goal->start_date->format('d/m/Y'),
                        'end_date' => $goal->end_date->format('d/m/Y'),
                        'days_remaining' => $goal->daysRemaining(),
                        'time_elapsed' => round($goal->timeElapsedPercent(), 1),
                        'is_on_track' => $progress !== null && $progress >= $goal->timeElapsedPercent(),
                        'achieved' => $goal->achieved,
                    ];
                })
                ->toArray();
        }

        // ===== GRAFICO DE SEGUIDORES (ultimos 30 dias) =====
        $followersChart = [];
        if ($brandId) {
            $accountIds = SocialAccount::where('brand_id', $brandId)
                ->where('is_active', true)
                ->pluck('id');

            if ($accountIds->isNotEmpty()) {
                $insights = SocialInsight::whereIn('social_account_id', $accountIds)
                    ->where('sync_status', 'success')
                    ->where('date', '>=', now()->subDays(30))
                    ->whereNotNull('followers_count')
                    ->orderBy('date')
                    ->get();

                // Agrupar por data, somar seguidores de todas contas
                $byDate = $insights->groupBy(fn($i) => $i->date->format('Y-m-d'));

                foreach ($byDate as $date => $group) {
                    $followersChart[] = [
                        'date' => Carbon::parse($date)->format('d/m'),
                        'date_full' => $date,
                        'followers' => $group->sum('followers_count'),
                        'engagement' => $group->sum('engagement'),
                        'reach' => $group->sum('reach'),
                        'impressions' => $group->sum('impressions'),
                    ];
                }
            }
        }

        // ===== ATIVIDADE RECENTE =====
        $recentActivity = [];
        if ($brandId) {
            // Posts recentes
            $recentPosts = $brand->posts()
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(fn($p) => [
                    'type' => 'post',
                    'title' => mb_substr($p->content ?? $p->title ?? 'Post', 0, 60) . '...',
                    'status' => $p->status,
                    'date' => $p->created_at->diffForHumans(),
                    'date_raw' => $p->created_at,
                ]);

            $recentActivity = $recentPosts->toArray();
        }

        // ===== ANALYTICS RESUMO (ultimos 30 dias) =====
        $analyticsSummary = null;
        if ($brandId) {
            $last30 = AnalyticsDailySummary::where('brand_id', $brandId)
                ->where('date', '>=', now()->subDays(30))
                ->get();

            // Periodo anterior (30-60 dias atras) para comparacao
            $prev30 = AnalyticsDailySummary::where('brand_id', $brandId)
                ->where('date', '>=', now()->subDays(60))
                ->where('date', '<', now()->subDays(30))
                ->get();

            $variation = function ($current, $previous) {
                if (!$previous || $previous == 0) return $current > 0 ? 100 : null;
                return round((($current - $previous) / abs($previous)) * 100, 1);
            };

            if ($last30->isNotEmpty()) {
                // Website (GA4)
                $sessions = $last30->sum('sessions');
                $users = $last30->sum('users');
                $pageviews = $last30->sum('pageviews');
                $bounceRate = $last30->avg('bounce_rate');
                $avgDuration = $last30->avg('avg_session_duration');

                // Ads
                $adSpend = $last30->sum('ad_spend');
                $adClicks = $last30->sum('ad_clicks');
                $adConversions = $last30->sum('ad_conversions');
                $adImpressions = $last30->sum('ad_impressions');
                $adRevenue = $last30->sum('ad_revenue');
                $adRoas = $last30->avg('ad_roas');
                $manualSpend = $last30->sum('manual_ad_spend');
                $totalSpend = $last30->sum('total_spend');

                // SEO
                $searchClicks = $last30->sum('search_clicks');
                $searchImpressions = $last30->sum('search_impressions');
                $searchPosition = $last30->avg('search_position');

                // E-commerce
                $wcRevenue = $last30->sum('wc_revenue');
                $wcOrders = $last30->sum('wc_orders');
                $wcAvgOrder = $wcOrders > 0 ? $wcRevenue / $wcOrders : 0;
                $realRoas = $totalSpend > 0 && $wcRevenue > 0 ? $wcRevenue / $totalSpend : 0;

                // Variacoes vs periodo anterior
                $prevSessions = $prev30->sum('sessions');
                $prevUsers = $prev30->sum('users');
                $prevAdSpend = $prev30->sum('ad_spend');
                $prevSearchClicks = $prev30->sum('search_clicks');
                $prevWcRevenue = $prev30->sum('wc_revenue');

                // Conexoes analytics
                $analyticsConnections = AnalyticsConnection::where('brand_id', $brandId)
                    ->where('is_active', true)
                    ->get(['platform', 'name', 'sync_status', 'last_synced_at'])
                    ->map(fn($c) => [
                        'platform' => $c->platform,
                        'name' => $c->name,
                        'label' => AnalyticsConnection::platformLabels()[$c->platform] ?? $c->platform,
                        'color' => AnalyticsConnection::platformColors()[$c->platform] ?? '#6B7280',
                        'sync_status' => $c->sync_status,
                        'last_synced_at' => $c->last_synced_at?->diffForHumans(),
                    ]);

                $hasWebsite = $sessions > 0 || $users > 0;
                $hasAds = $adSpend > 0 || $totalSpend > 0;
                $hasSeo = $searchClicks > 0;
                $hasWc = $wcRevenue > 0 || $wcOrders > 0;

                $analyticsSummary = [
                    // Website
                    'sessions' => $sessions,
                    'sessions_variation' => $variation($sessions, $prevSessions),
                    'users' => $users,
                    'users_variation' => $variation($users, $prevUsers),
                    'pageviews' => $pageviews,
                    'bounce_rate' => round($bounceRate, 1),
                    'avg_session_duration' => round($avgDuration),
                    // Ads
                    'ad_spend' => round($adSpend, 2),
                    'ad_spend_variation' => $variation($adSpend, $prevAdSpend),
                    'manual_spend' => round($manualSpend, 2),
                    'total_spend' => round($totalSpend, 2),
                    'ad_clicks' => $adClicks,
                    'ad_conversions' => $adConversions,
                    'ad_impressions' => $adImpressions,
                    'ad_roas' => round($adRoas, 2),
                    // SEO
                    'search_clicks' => $searchClicks,
                    'search_clicks_variation' => $variation($searchClicks, $prevSearchClicks),
                    'search_impressions' => $searchImpressions,
                    'search_position' => round($searchPosition, 1),
                    // E-commerce
                    'wc_revenue' => round($wcRevenue, 2),
                    'wc_revenue_variation' => $variation($wcRevenue, $prevWcRevenue),
                    'wc_orders' => $wcOrders,
                    'wc_avg_order_value' => round($wcAvgOrder, 2),
                    'real_roas' => round($realRoas, 2),
                    // Flags
                    'has_website' => $hasWebsite,
                    'has_ads' => $hasAds,
                    'has_seo' => $hasSeo,
                    'has_wc' => $hasWc,
                    'has_any' => $hasWebsite || $hasAds || $hasSeo || $hasWc,
                    // Conexoes
                    'connections' => $analyticsConnections,
                    'connections_count' => $analyticsConnections->count(),
                ];
            }
        }

        return Inertia::render('Dashboard/Index', [
            'stats' => $stats,
            'socialAccounts' => $socialAccounts,
            'socialSummary' => $socialSummary,
            'metrics' => $metrics,
            'activeGoals' => $activeGoals,
            'followersChart' => $followersChart,
            'recentActivity' => $recentActivity,
            'analyticsSummary' => $analyticsSummary,
        ]);
    }
}

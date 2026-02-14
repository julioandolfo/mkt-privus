<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsConnection;
use App\Models\AnalyticsDailySummary;
use App\Models\Brand;
use App\Models\CustomMetric;
use App\Models\EmailAiSuggestion;
use App\Models\EmailCampaign;
use App\Models\EmailContact;
use App\Models\EmailProvider;
use App\Models\ManualAdEntry;
use App\Models\MetricGoal;
use App\Models\Post;
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

        // ===== FILTRO DE MARCA (global ou especifica) =====
        $brandFilter = $request->get('brand', 'all'); // 'all' ou id numerico
        $allBrands = Brand::orderBy('name')->get(['id', 'name']);

        if ($brandFilter === 'all' || $brandFilter === null) {
            $brandId = null;
            $brand = null;
            $brandIds = null; // null = sem filtro, pegar tudo
        } else {
            $brand = Brand::find($brandFilter);
            $brandId = $brand?->id;
            $brandIds = $brandId ? [$brandId] : null;
        }

        // ===== PERIODO DO DASHBOARD =====
        $period = $request->get('period', 'this_month');
        $customStart = $request->get('start');
        $customEnd = $request->get('end');

        [$periodStart, $periodEnd, $prevStart, $prevEnd, $periodLabel] = $this->resolvePeriod($period, $customStart, $customEnd);

        // Helper: aplica filtro de marca (null = global/todas)
        $scopeBrand = function ($query) use ($brandId) {
            if ($brandId) {
                $query->where('brand_id', $brandId);
            }
            return $query;
        };

        // ===== STATS BASICOS =====
        $postsQuery = Post::query();
        if ($brandId) $postsQuery->where('brand_id', $brandId);

        $stats = [
            'posts_this_month' => (clone $postsQuery)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            'scheduled_posts' => (clone $postsQuery)
                ->where('status', 'scheduled')
                ->count(),
            'published_posts' => (clone $postsQuery)
                ->where('status', 'published')
                ->count(),
            'connected_platforms' => SocialAccount::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                ->where('is_active', true)
                ->count(),
        ];

        // ===== CONTAS SOCIAIS COM INSIGHTS =====
        $socialAccounts = [];
        $totalFollowers = 0;
        $totalFollowersYesterday = 0;

        {
            $accounts = SocialAccount::when($brandId, fn($q) => $q->where('brand_id', $brandId))
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

                $platformData = $latest?->platform_data ?? [];

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
                    'shares' => $latest?->shares,
                    'saves' => $latest?->saves,
                    'clicks' => $latest?->clicks,
                    'video_views' => $latest?->video_views,
                    'profile_views' => $platformData['profile_views'] ?? null,
                    'stories_count' => $platformData['stories_count'] ?? null,
                    'reels_count' => $platformData['reels_count'] ?? null,
                    'avg_reach_per_post' => $platformData['avg_reach_per_post'] ?? null,
                    'posts_total_30d' => $platformData['posts_total_30d'] ?? null,
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
        {
            $metrics = CustomMetric::when($brandId, fn($q) => $q->where('brand_id', $brandId))
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
        {
            $activeGoals = MetricGoal::whereHas('metric', function ($q) use ($brandId) {
                    $q->when($brandId, fn($q2) => $q2->where('brand_id', $brandId))->where('is_active', true);
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

        // ===== GRAFICO DE SEGUIDORES (periodo selecionado) =====
        $followersChart = [];
        {
            $accountIds = SocialAccount::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                ->where('is_active', true)
                ->pluck('id');

            if ($accountIds->isNotEmpty()) {
                $insights = SocialInsight::whereIn('social_account_id', $accountIds)
                    ->where('sync_status', 'success')
                    ->whereBetween('date', [$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')])
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
        {
            $recentPosts = Post::when($brandId, fn($q) => $q->where('brand_id', $brandId))
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

        // ===== ANALYTICS RESUMO (periodo selecionado) =====
        $analyticsSummary = null;
        {
            // Filtro de marca para summaries: se brand especifico, filtra por ele.
            // Se "todas", pega apenas linhas com brand_id preenchido (evita duplicar com brand_id NULL).
            $scopeSummary = function ($query) use ($brandId) {
                if ($brandId) {
                    $query->where('brand_id', $brandId);
                } else {
                    $query->whereNotNull('brand_id');
                }
                return $query;
            };

            $last30 = $scopeSummary(AnalyticsDailySummary::query())
                ->whereBetween('date', [$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')])
                ->get();

            // Periodo anterior para comparacao
            $prev30 = $scopeSummary(AnalyticsDailySummary::query())
                ->whereBetween('date', [$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')])
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
                $analyticsConnections = AnalyticsConnection::when($brandId, fn($q) => $q->where('brand_id', $brandId))
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

        // ===== EMAIL MARKETING RESUMO =====
        $emailSummary = null;
        try {
            // Verifica se as tabelas existem antes de consultar
            if (!\Illuminate\Support\Facades\Schema::hasTable('email_providers')) {
                throw new \RuntimeException('Email tables not migrated');
            }

            $hasProviders = EmailProvider::when($brandId, fn($q) => $q->where('brand_id', $brandId))->exists();
            $hasCampaigns = EmailCampaign::when($brandId, fn($q) => $q->where('brand_id', $brandId))->exists();
            $hasContacts = EmailContact::when($brandId, fn($q) => $q->where('brand_id', $brandId))->exists();

            if ($hasProviders || $hasCampaigns || $hasContacts) {
                // Campanhas no periodo (usar copias de datas para seguranca)
                $pStart = $periodStart->copy();
                $pEnd = $periodEnd->copy();
                $prStart = $prevStart->copy();
                $prEnd = $prevEnd->copy();

                $emailCampaignsQuery = EmailCampaign::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                    ->whereIn('status', ['sent', 'sending'])
                    ->whereBetween('started_at', [$pStart, $pEnd]);

                $emailAgg = (clone $emailCampaignsQuery)->selectRaw('
                    COUNT(*) as campaigns_sent,
                    COALESCE(SUM(total_sent), 0) as total_sent,
                    COALESCE(SUM(total_delivered), 0) as total_delivered,
                    COALESCE(SUM(total_bounced), 0) as total_bounced,
                    COALESCE(SUM(total_opened), 0) as total_opened,
                    COALESCE(SUM(total_clicked), 0) as total_clicked,
                    COALESCE(SUM(total_unsubscribed), 0) as total_unsubscribed,
                    COALESCE(SUM(unique_opens), 0) as unique_opens,
                    COALESCE(SUM(unique_clicks), 0) as unique_clicks
                ')->first();

                $eSent = (int) ($emailAgg->total_sent ?? 0);
                $eDelivered = (int) ($emailAgg->total_delivered ?? 0);
                $eUniqueOpens = (int) ($emailAgg->unique_opens ?? 0);
                $eUniqueClicks = (int) ($emailAgg->unique_clicks ?? 0);

                // Periodo anterior
                $prevEmailAgg = EmailCampaign::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                    ->whereIn('status', ['sent', 'sending'])
                    ->whereBetween('started_at', [$prStart, $prEnd])
                    ->selectRaw('
                        COALESCE(SUM(total_sent), 0) as total_sent,
                        COALESCE(SUM(total_delivered), 0) as total_delivered,
                        COALESCE(SUM(unique_opens), 0) as unique_opens,
                        COALESCE(SUM(unique_clicks), 0) as unique_clicks
                    ')->first();

                $prevEDelivered = (int) ($prevEmailAgg->total_delivered ?? 0);
                $prevEUniqueOpens = (int) ($prevEmailAgg->unique_opens ?? 0);
                $prevEUniqueClicks = (int) ($prevEmailAgg->unique_clicks ?? 0);

                $emailSummary = [
                    'has_email' => true,
                    'campaigns_sent' => (int) ($emailAgg->campaigns_sent ?? 0),
                    'total_sent' => $eSent,
                    'total_delivered' => $eDelivered,
                    'total_bounced' => (int) ($emailAgg->total_bounced ?? 0),
                    'total_opened' => (int) ($emailAgg->total_opened ?? 0),
                    'total_clicked' => (int) ($emailAgg->total_clicked ?? 0),
                    'total_unsubscribed' => (int) ($emailAgg->total_unsubscribed ?? 0),
                    'unique_opens' => $eUniqueOpens,
                    'unique_clicks' => $eUniqueClicks,
                    'open_rate' => $eDelivered > 0 ? round(($eUniqueOpens / $eDelivered) * 100, 2) : 0,
                    'click_rate' => $eDelivered > 0 ? round(($eUniqueClicks / $eDelivered) * 100, 2) : 0,
                    'bounce_rate' => $eSent > 0 ? round(((int)($emailAgg->total_bounced ?? 0) / $eSent) * 100, 2) : 0,
                    'delivery_rate' => $eSent > 0 ? round(($eDelivered / $eSent) * 100, 2) : 0,
                    // Contatos
                    'total_contacts' => EmailContact::when($brandId, fn($q) => $q->where('brand_id', $brandId))->count(),
                    'active_contacts' => EmailContact::when($brandId, fn($q) => $q->where('brand_id', $brandId))->where('status', 'active')->count(),
                    // Comparacao
                    'prev_total_sent' => (int) ($prevEmailAgg->total_sent ?? 0),
                    'prev_open_rate' => $prevEDelivered > 0 ? round(($prevEUniqueOpens / $prevEDelivered) * 100, 2) : 0,
                    'prev_click_rate' => $prevEDelivered > 0 ? round(($prevEUniqueClicks / $prevEDelivered) * 100, 2) : 0,
                    // Sugestoes IA pendentes
                    'pending_suggestions' => EmailAiSuggestion::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                        ->where('status', 'pending')->count(),
                ];
            }
        } catch (\Throwable $e) {
            // Se as tabelas de email nao existem ainda, ignorar silenciosamente
            $emailSummary = null;
        }

        // ===== SMS MARKETING SUMMARY =====
        $smsSummary = null;
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('sms_campaigns')) {
                $hasSms = \App\Models\SmsCampaign::when($brandId, fn($q) => $q->where('brand_id', $brandId))->exists();

                if ($hasSms) {
                    $smsStart = $periodStart->copy();
                    $smsEnd = $periodEnd->copy();
                    $smsPrevStart = $prevStart->copy();
                    $smsPrevEnd = $prevEnd->copy();

                    $smsAgg = \App\Models\SmsCampaign::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                        ->whereIn('status', ['sent', 'sending'])
                        ->whereBetween('started_at', [$smsStart, $smsEnd])
                        ->selectRaw('
                            COUNT(*) as campaigns_sent,
                            COALESCE(SUM(total_sent), 0) as total_sent,
                            COALESCE(SUM(total_delivered), 0) as total_delivered,
                            COALESCE(SUM(total_failed), 0) as total_failed,
                            COALESCE(SUM(total_clicked), 0) as total_clicked
                        ')->first();

                    $sSent = (int) ($smsAgg->total_sent ?? 0);
                    $sDelivered = (int) ($smsAgg->total_delivered ?? 0);
                    $sClicked = (int) ($smsAgg->total_clicked ?? 0);

                    $prevSmsAgg = \App\Models\SmsCampaign::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                        ->whereIn('status', ['sent', 'sending'])
                        ->whereBetween('started_at', [$smsPrevStart, $smsPrevEnd])
                        ->selectRaw('
                            COALESCE(SUM(total_sent), 0) as total_sent,
                            COALESCE(SUM(total_delivered), 0) as total_delivered
                        ')->first();

                    $smsSummary = [
                        'has_sms' => true,
                        'campaigns_sent' => (int) ($smsAgg->campaigns_sent ?? 0),
                        'total_sent' => $sSent,
                        'total_delivered' => $sDelivered,
                        'total_failed' => (int) ($smsAgg->total_failed ?? 0),
                        'total_clicked' => $sClicked,
                        'delivery_rate' => $sSent > 0 ? round(($sDelivered / $sSent) * 100, 2) : 0,
                        'click_rate' => $sDelivered > 0 ? round(($sClicked / $sDelivered) * 100, 2) : 0,
                        'prev_total_sent' => (int) ($prevSmsAgg->total_sent ?? 0),
                        'pending_suggestions' => \App\Models\EmailAiSuggestion::when($brandId, fn($q) => $q->where('brand_id', $brandId))
                            ->where('content_type', 'sms')
                            ->where('status', 'pending')
                            ->count(),
                    ];
                }
            }
        } catch (\Throwable $e) {
            $smsSummary = null;
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
            'emailSummary' => $emailSummary,
            'smsSummary' => $smsSummary,
            'period' => $period,
            'periodLabel' => $periodLabel,
            'periodStart' => $periodStart->format('Y-m-d'),
            'periodEnd' => $periodEnd->format('Y-m-d'),
            'brandFilter' => $brandFilter,
            'allBrands' => $allBrands,
        ]);
    }

    /**
     * Resolver datas de inicio/fim com base no periodo selecionado.
     * Retorna [$start, $end, $prevStart, $prevEnd, $label]
     */
    private function resolvePeriod(string $period, ?string $customStart = null, ?string $customEnd = null): array
    {
        $now = Carbon::now();

        switch ($period) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $prevStart = $now->copy()->subDay()->startOfDay();
                $prevEnd = $now->copy()->subDay()->endOfDay();
                $label = 'Hoje';
                break;

            case 'yesterday':
                $start = $now->copy()->subDay()->startOfDay();
                $end = $now->copy()->subDay()->endOfDay();
                $prevStart = $now->copy()->subDays(2)->startOfDay();
                $prevEnd = $now->copy()->subDays(2)->endOfDay();
                $label = 'Ontem';
                break;

            case 'this_week':
                $start = $now->copy()->startOfWeek();
                $end = $now->copy()->endOfDay();
                $daysDiff = $start->diffInDays($end) + 1;
                $prevStart = $start->copy()->subDays($daysDiff);
                $prevEnd = $start->copy()->subDay();
                $label = 'Esta Semana';
                break;

            case 'last_month':
                $start = $now->copy()->subMonth()->startOfMonth();
                $end = $now->copy()->subMonth()->endOfMonth();
                $prevStart = $now->copy()->subMonths(2)->startOfMonth();
                $prevEnd = $now->copy()->subMonths(2)->endOfMonth();
                $label = 'Mes Passado';
                break;

            case 'last_7':
                $start = $now->copy()->subDays(6)->startOfDay();
                $end = $now->copy()->endOfDay();
                $prevStart = $now->copy()->subDays(13)->startOfDay();
                $prevEnd = $now->copy()->subDays(7)->endOfDay();
                $label = 'Ultimos 7 dias';
                break;

            case 'last_30':
                $start = $now->copy()->subDays(29)->startOfDay();
                $end = $now->copy()->endOfDay();
                $prevStart = $now->copy()->subDays(59)->startOfDay();
                $prevEnd = $now->copy()->subDays(30)->endOfDay();
                $label = 'Ultimos 30 dias';
                break;

            case 'custom':
                $start = $customStart ? Carbon::parse($customStart)->startOfDay() : $now->copy()->startOfMonth();
                $end = $customEnd ? Carbon::parse($customEnd)->endOfDay() : $now->copy()->endOfDay();
                $daysDiff = $start->diffInDays($end) + 1;
                $prevStart = $start->copy()->subDays($daysDiff);
                $prevEnd = $start->copy()->subDay();
                $label = $start->format('d/m') . ' - ' . $end->format('d/m');
                break;

            case 'this_month':
            default:
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfDay();
                $prevStart = $now->copy()->subMonth()->startOfMonth();
                $prevEnd = $now->copy()->subMonth()->endOfMonth();
                $label = 'Este Mes';
                break;
        }

        return [$start, $end, $prevStart, $prevEnd, $label];
    }
}

<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SystemLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SocialInsightsService
{
    /**
     * Sincroniza insights de uma conta social
     */
    public function syncAccount(SocialAccount $account, ?Carbon $date = null): ?SocialInsight
    {
        $date = $date ?? now()->toDateString();

        try {
            $platform = $account->platform->value ?? $account->platform;
            $data = match ($platform) {
                'instagram' => $this->fetchInstagramInsights($account),
                'facebook' => $this->fetchFacebookInsights($account),
                'youtube' => $this->fetchYoutubeInsights($account),
                'tiktok' => $this->fetchTiktokInsights($account),
                'linkedin' => $this->fetchLinkedinInsights($account),
                'pinterest' => $this->fetchPinterestInsights($account),
                default => null,
            };

            if (!$data) {
                return null;
            }

            // Calcular crescimento de seguidores
            $previousInsight = SocialInsight::where('social_account_id', $account->id)
                ->where('date', '<', $date)
                ->orderByDesc('date')
                ->first();

            if ($previousInsight && $data['followers_count'] && $previousInsight->followers_count) {
                $data['net_followers'] = $data['followers_count'] - $previousInsight->followers_count;
                $data['followers_gained'] = max(0, $data['net_followers']);
                $data['followers_lost'] = min(0, $data['net_followers']) * -1;
            }

            // Calcular engagement rate
            if (($data['engagement'] ?? 0) > 0 && ($data['followers_count'] ?? 0) > 0) {
                $data['engagement_rate'] = round(($data['engagement'] / $data['followers_count']) * 100, 4);
            }

            $insight = SocialInsight::updateOrCreate(
                [
                    'social_account_id' => $account->id,
                    'date' => $date,
                ],
                array_merge($data, [
                    'brand_id' => $account->brand_id,
                    'sync_status' => 'success',
                ])
            );

            SystemLog::info('social', 'insights.sync', "Insights sincronizados para {$platform}: {$account->display_name}", [
                'account_id' => $account->id,
                'platform' => $platform,
                'followers' => $data['followers_count'] ?? null,
                'reach' => $data['reach'] ?? null,
                'impressions' => $data['impressions'] ?? null,
                'engagement' => $data['engagement'] ?? null,
                'engagement_rate' => $data['engagement_rate'] ?? null,
                'likes' => $data['likes'] ?? null,
                'comments' => $data['comments'] ?? null,
                'saves' => $data['saves'] ?? null,
                'shares' => $data['shares'] ?? null,
                'clicks' => $data['clicks'] ?? null,
            ]);

            return $insight;

        } catch (\Throwable $e) {
            Log::error("Social insights sync failed for account {$account->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            SystemLog::error('social', 'insights.sync.error', "Erro ao sincronizar insights: {$e->getMessage()}", [
                'account_id' => $account->id,
                'platform' => $account->platform->value ?? $account->platform,
            ]);

            // Registrar falha
            SocialInsight::updateOrCreate(
                ['social_account_id' => $account->id, 'date' => $date ?? now()->toDateString()],
                [
                    'brand_id' => $account->brand_id,
                    'sync_status' => 'error',
                    'sync_error' => substr($e->getMessage(), 0, 500),
                ]
            );

            return null;
        }
    }

    /**
     * Sincroniza todas as contas ativas de uma brand
     */
    public function syncBrand(int $brandId): array
    {
        $accounts = SocialAccount::where('brand_id', $brandId)
            ->where('is_active', true)
            ->get();

        $results = [];
        foreach ($accounts as $account) {
            $results[$account->id] = $this->syncAccount($account);
        }

        return $results;
    }

    /**
     * Sincroniza TODAS as contas ativas do sistema
     */
    public function syncAll(): array
    {
        $accounts = SocialAccount::where('is_active', true)->get();
        $results = [];

        foreach ($accounts as $account) {
            $results[$account->id] = $this->syncAccount($account);
        }

        return $results;
    }

    // ================================================================
    // INSTAGRAM INSIGHTS (via Instagram Graph API / Facebook Graph API)
    // ================================================================

    private function fetchInstagramInsights(SocialAccount $account): array
    {
        $token = $account->access_token;
        $igUserId = $account->platform_user_id;
        $apiVersion = config('social_oauth.meta.api_version', 'v19.0');

        $data = [
            'followers_count' => null,
            'following_count' => null,
            'posts_count' => null,
            'impressions' => null,
            'reach' => null,
            'engagement' => null,
            'engagement_rate' => null,
            'likes' => null,
            'comments' => null,
            'shares' => null,
            'saves' => null,
            'clicks' => null,
            'video_views' => null,
            'platform_data' => [],
        ];

        // 1. Dados basicos do perfil
        $profile = Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}", [
            'access_token' => $token,
            'fields' => 'followers_count,follows_count,media_count,name,username,biography,profile_picture_url',
        ]);

        if ($profile->successful()) {
            $p = $profile->json();
            $data['followers_count'] = $p['followers_count'] ?? null;
            $data['following_count'] = $p['follows_count'] ?? null;
            $data['posts_count'] = $p['media_count'] ?? null;
            $data['platform_data']['username'] = $p['username'] ?? null;
            $data['platform_data']['biography'] = $p['biography'] ?? null;
        }

        // 2. Insights do perfil (ultimos 28 dias)
        // Tentativa 1: metric_type=total_value (API v18+)
        $since28 = now()->subDays(28)->startOfDay()->timestamp;
        $untilNow = now()->endOfDay()->timestamp;

        $insightMetrics = 'impressions,reach,profile_views,website_clicks,accounts_engaged,follows_and_unfollows';

        $insights = Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/insights", [
            'access_token' => $token,
            'metric' => $insightMetrics,
            'period' => 'day',
            'metric_type' => 'total_value',
            'since' => $since28,
            'until' => $untilNow,
        ]);

        $insightsParsed = false;

        if ($insights->successful() && !empty($insights->json('data'))) {
            foreach ($insights->json('data', []) as $metric) {
                $value = $metric['total_value']['value']
                    ?? $metric['values'][0]['value']
                    ?? null;

                if ($value === null) continue;
                $insightsParsed = true;

                match ($metric['name']) {
                    'impressions' => $data['impressions'] = $value,
                    'reach' => $data['reach'] = $value,
                    'profile_views' => $data['platform_data']['profile_views'] = $value,
                    'website_clicks' => $data['clicks'] = $value,
                    'accounts_engaged' => $data['platform_data']['accounts_engaged'] = $value,
                    'follows_and_unfollows' => $data['platform_data']['net_followers'] = $value,
                    default => null,
                };
            }
        }

        // Tentativa 2: sem metric_type (API antiga) — buscar metricas individuais
        if (!$insightsParsed) {
            $insightsLegacy = Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/insights", [
                'access_token' => $token,
                'metric' => 'impressions,reach,profile_views,website_clicks',
                'period' => 'day',
                'since' => $since28,
                'until' => $untilNow,
            ]);

            if ($insightsLegacy->successful() && !empty($insightsLegacy->json('data'))) {
                foreach ($insightsLegacy->json('data', []) as $metric) {
                    // Somar valores diarios do periodo
                    $sum = 0;
                    foreach ($metric['values'] ?? [] as $dayValue) {
                        $sum += $dayValue['value'] ?? 0;
                    }
                    if ($sum > 0) {
                        $insightsParsed = true;
                        match ($metric['name']) {
                            'impressions' => $data['impressions'] = $sum,
                            'reach' => $data['reach'] = $sum,
                            'profile_views' => $data['platform_data']['profile_views'] = $sum,
                            'website_clicks' => $data['clicks'] = $sum,
                            default => null,
                        };
                    }
                }
            }
        }

        // Tentativa 3: periodo mais curto (7 dias) se 28 dias falhou
        if (!$insightsParsed) {
            $insightsFallback = Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/insights", [
                'access_token' => $token,
                'metric' => 'impressions,reach',
                'period' => 'day',
                'since' => now()->subDays(7)->startOfDay()->timestamp,
                'until' => $untilNow,
            ]);

            if ($insightsFallback->successful() && !empty($insightsFallback->json('data'))) {
                foreach ($insightsFallback->json('data', []) as $metric) {
                    $sum = 0;
                    foreach ($metric['values'] ?? [] as $dayValue) {
                        $sum += $dayValue['value'] ?? 0;
                    }
                    if ($sum > 0) {
                        $insightsParsed = true;
                        match ($metric['name']) {
                            'impressions' => $data['impressions'] = $sum,
                            'reach' => $data['reach'] = $sum,
                            default => null,
                        };
                    }
                }
            }

            // Log detalhado de todas as tentativas para debug
            if (!$insightsParsed) {
                SystemLog::warning('social', 'insights.ig.reach_empty', "Instagram insights API: todas as tentativas falharam", [
                    'account_id' => $account->id,
                    'username' => $account->username,
                    'attempt1_status' => $insights->status(),
                    'attempt1_error' => $insights->json('error') ?? null,
                    'attempt1_data_count' => count($insights->json('data', [])),
                    'attempt2_exists' => isset($insightsLegacy),
                    'attempt2_status' => isset($insightsLegacy) ? $insightsLegacy->status() : null,
                    'attempt2_error' => isset($insightsLegacy) ? ($insightsLegacy->json('error') ?? null) : null,
                    'attempt3_status' => $insightsFallback->status(),
                    'attempt3_error' => $insightsFallback->json('error') ?? null,
                    'since_28' => date('Y-m-d', $since28),
                    'until' => date('Y-m-d', $untilNow),
                ]);
            }
        }

        // 3. Engajamento dos posts recentes (ultimos 50 posts, filtrar por 30 dias)
        $media = Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/media", [
            'access_token' => $token,
            'fields' => 'id,timestamp,like_count,comments_count,media_type,media_product_type',
            'limit' => 50,
        ]);

        $totalLikes = 0;
        $totalComments = 0;
        $recentPostIds = [];
        $recentPostCount = 0;
        $storiesCount = 0;
        $reelsCount = 0;

        if ($media->successful()) {
            foreach ($media->json('data', []) as $post) {
                $postDate = Carbon::parse($post['timestamp']);
                if ($postDate->gte(now()->subDays(30))) {
                    $totalLikes += $post['like_count'] ?? 0;
                    $totalComments += $post['comments_count'] ?? 0;
                    $recentPostIds[] = $post['id'];
                    $recentPostCount++;

                    // Contar tipos de conteudo
                    $productType = $post['media_product_type'] ?? '';
                    $mediaType = $post['media_type'] ?? '';
                    if ($productType === 'STORY' || $mediaType === 'STORY') {
                        $storiesCount++;
                    } elseif ($productType === 'REELS' || $mediaType === 'VIDEO') {
                        $reelsCount++;
                    }
                }
            }
        }

        $data['likes'] = $totalLikes;
        $data['comments'] = $totalComments;
        $data['platform_data']['stories_count'] = $storiesCount;
        $data['platform_data']['reels_count'] = $reelsCount;

        // Medias por post
        if ($recentPostCount > 0) {
            $data['platform_data']['avg_likes_per_post'] = round($totalLikes / $recentPostCount, 1);
            $data['platform_data']['avg_comments_per_post'] = round($totalComments / $recentPostCount, 1);
        }

        // 4. Insights detalhados dos posts (saves, shares, video_views, reach por post)
        $totalSaves = 0;
        $totalShares = 0;
        $totalVideoViews = 0;
        $totalPostReach = 0;
        $totalPostImpressions = 0;

        foreach (array_slice($recentPostIds, 0, 15) as $mediaId) {
            $mediaInsights = Http::get("https://graph.facebook.com/{$apiVersion}/{$mediaId}/insights", [
                'access_token' => $token,
                'metric' => 'saved,shares,video_views,reach,impressions,total_interactions',
            ]);

            if ($mediaInsights->successful()) {
                foreach ($mediaInsights->json('data', []) as $mi) {
                    $val = $mi['values'][0]['value'] ?? 0;
                    match ($mi['name']) {
                        'saved' => $totalSaves += $val,
                        'shares' => $totalShares += $val,
                        'video_views' => $totalVideoViews += $val,
                        'reach' => $totalPostReach += $val,
                        'impressions' => $totalPostImpressions += $val,
                        default => null,
                    };
                }
            }
        }

        $data['saves'] = $totalSaves;
        $data['shares'] = $totalShares;
        $data['video_views'] = $totalVideoViews > 0 ? $totalVideoViews : null;
        $data['engagement'] = $totalLikes + $totalComments + $totalSaves + $totalShares;

        // Se reach da conta veio vazio, usar soma de reach dos posts como fallback
        if (!$data['reach'] && $totalPostReach > 0) {
            $data['reach'] = $totalPostReach;
            $data['platform_data']['reach_source'] = 'posts_sum';
        }
        if (!$data['impressions'] && $totalPostImpressions > 0) {
            $data['impressions'] = $totalPostImpressions;
            $data['platform_data']['impressions_source'] = 'posts_sum';
        }

        // Engagement rate = (likes + comments + saves + shares) / followers * 100
        if ($data['followers_count'] && $data['followers_count'] > 0) {
            $data['engagement_rate'] = round($data['engagement'] / $data['followers_count'] * 100, 2);
        }

        // Posts insights summary
        $data['platform_data']['posts_analyzed'] = min(count($recentPostIds), 15);
        $data['platform_data']['posts_total_30d'] = $recentPostCount;
        $data['platform_data']['avg_reach_per_post'] = count($recentPostIds) > 0 ? round($totalPostReach / min(count($recentPostIds), 15)) : null;
        $data['platform_data']['avg_impressions_per_post'] = count($recentPostIds) > 0 ? round($totalPostImpressions / min(count($recentPostIds), 15)) : null;

        // 5. Audience demographics
        // Tentativa com API nova (v18+): engaged_audience_demographics
        $audienceParsed = false;

        $audienceNew = Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/insights", [
            'access_token' => $token,
            'metric' => 'engaged_audience_demographics',
            'period' => 'lifetime',
            'metric_type' => 'total_value',
            'breakdown' => 'age,gender,city,country',
        ]);

        if ($audienceNew->successful() && !empty($audienceNew->json('data'))) {
            $audienceParsed = true;
            foreach ($audienceNew->json('data', []) as $metric) {
                $results = $metric['total_value']['breakdowns'][0]['results'] ?? [];
                $this->parseAudienceBreakdowns($data, $results);
            }
        }

        // Fallback: API legada (lifetime audience)
        if (!$audienceParsed) {
            $audienceInsights = Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/insights", [
                'access_token' => $token,
                'metric' => 'follower_demographics',
                'period' => 'lifetime',
                'metric_type' => 'total_value',
                'breakdown' => 'age,gender,city,country',
            ]);

            if ($audienceInsights->successful() && !empty($audienceInsights->json('data'))) {
                $audienceParsed = true;
                foreach ($audienceInsights->json('data', []) as $metric) {
                    $results = $metric['total_value']['breakdowns'][0]['results'] ?? [];
                    $this->parseAudienceBreakdowns($data, $results);
                }
            }
        }

        // Fallback final: API antiga (audience_gender_age, audience_city, audience_country)
        if (!$audienceParsed) {
            $audienceLegacy = Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/insights", [
                'access_token' => $token,
                'metric' => 'audience_gender_age,audience_city,audience_country',
                'period' => 'lifetime',
            ]);

            if ($audienceLegacy->successful()) {
                foreach ($audienceLegacy->json('data', []) as $metric) {
                    $values = $metric['values'][0]['value'] ?? [];

                    if ($metric['name'] === 'audience_gender_age' && is_array($values)) {
                        $genders = ['male' => 0, 'female' => 0, 'other' => 0];
                        $ages = [];
                        foreach ($values as $key => $count) {
                            [$gender, $age] = explode('.', $key) + ['', ''];
                            match ($gender) {
                                'M' => $genders['male'] += $count,
                                'F' => $genders['female'] += $count,
                                default => $genders['other'] += $count,
                            };
                            $ages[$age] = ($ages[$age] ?? 0) + $count;
                        }
                        $total = array_sum($genders);
                        if ($total > 0) {
                            $data['audience_gender'] = [
                                'male' => round($genders['male'] / $total * 100, 1),
                                'female' => round($genders['female'] / $total * 100, 1),
                                'other' => round($genders['other'] / $total * 100, 1),
                            ];
                            $data['audience_age'] = collect($ages)->mapWithKeys(fn($v, $k) => [$k => round($v / $total * 100, 1)])->toArray();
                        }
                    }

                    if ($metric['name'] === 'audience_city' && is_array($values)) {
                        $total = array_sum($values);
                        $data['audience_cities'] = collect($values)->sortDesc()->take(10)
                            ->mapWithKeys(fn($v, $k) => [$k => round($v / max($total, 1) * 100, 1)])->toArray();
                    }

                    if ($metric['name'] === 'audience_country' && is_array($values)) {
                        $total = array_sum($values);
                        $data['audience_countries'] = collect($values)->sortDesc()->take(10)
                            ->mapWithKeys(fn($v, $k) => [$k => round($v / max($total, 1) * 100, 1)])->toArray();
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Parse audience breakdowns da API nova do Instagram (v18+).
     */
    private function parseAudienceBreakdowns(array &$data, array $results): void
    {
        $genders = ['male' => 0, 'female' => 0, 'other' => 0];
        $ages = [];
        $cities = [];
        $countries = [];

        foreach ($results as $result) {
            $dims = $result['dimension_values'] ?? [];
            $value = $result['value'] ?? 0;

            // Gender + Age breakdown
            if (count($dims) >= 2) {
                $gender = strtolower($dims[1] ?? '');
                $age = $dims[0] ?? '';

                match ($gender) {
                    'm', 'male' => $genders['male'] += $value,
                    'f', 'female' => $genders['female'] += $value,
                    default => $genders['other'] += $value,
                };
                if ($age) {
                    $ages[$age] = ($ages[$age] ?? 0) + $value;
                }
            }

            // City breakdown
            if (count($dims) >= 3 && !empty($dims[2])) {
                $cities[$dims[2]] = ($cities[$dims[2]] ?? 0) + $value;
            }

            // Country breakdown
            if (count($dims) >= 4 && !empty($dims[3])) {
                $countries[$dims[3]] = ($countries[$dims[3]] ?? 0) + $value;
            }
        }

        $totalGender = array_sum($genders);
        if ($totalGender > 0) {
            $data['audience_gender'] = [
                'male' => round($genders['male'] / $totalGender * 100, 1),
                'female' => round($genders['female'] / $totalGender * 100, 1),
                'other' => round($genders['other'] / $totalGender * 100, 1),
            ];
        }
        if (!empty($ages)) {
            $totalAge = array_sum($ages);
            $data['audience_age'] = collect($ages)->mapWithKeys(fn($v, $k) => [$k => round($v / max($totalAge, 1) * 100, 1)])->toArray();
        }
        if (!empty($cities)) {
            $totalCities = array_sum($cities);
            $data['audience_cities'] = collect($cities)->sortDesc()->take(10)
                ->mapWithKeys(fn($v, $k) => [$k => round($v / max($totalCities, 1) * 100, 1)])->toArray();
        }
        if (!empty($countries)) {
            $totalCountries = array_sum($countries);
            $data['audience_countries'] = collect($countries)->sortDesc()->take(10)
                ->mapWithKeys(fn($v, $k) => [$k => round($v / max($totalCountries, 1) * 100, 1)])->toArray();
        }
    }

    // ================================================================
    // FACEBOOK INSIGHTS (Page Insights API)
    // ================================================================

    private function fetchFacebookInsights(SocialAccount $account): array
    {
        $token = $account->access_token;
        $pageId = $account->platform_user_id;
        $apiVersion = config('social_oauth.meta.api_version', 'v19.0');

        $data = [
            'followers_count' => null,
            'posts_count' => null,
            'impressions' => null,
            'reach' => null,
            'engagement' => null,
            'likes' => null,
            'comments' => null,
            'shares' => null,
            'clicks' => null,
            'video_views' => null,
            'platform_data' => [],
        ];

        // 1. Dados basicos da pagina
        $page = Http::get("https://graph.facebook.com/{$apiVersion}/{$pageId}", [
            'access_token' => $token,
            'fields' => 'followers_count,fan_count,name,about,category',
        ]);

        if ($page->successful()) {
            $p = $page->json();
            $data['followers_count'] = $p['followers_count'] ?? $p['fan_count'] ?? null;
            $data['platform_data']['fan_count'] = $p['fan_count'] ?? null;
            $data['platform_data']['category'] = $p['category'] ?? null;
        }

        // 2. Page Insights (ultimos 7 dias, somando diarios)
        $metrics = 'page_impressions,page_impressions_unique,page_engaged_users,page_post_engagements,page_fan_adds,page_fan_removes,page_views_total,page_actions_post_reactions_like_total';

        $since7 = now()->subDays(7)->startOfDay()->timestamp;
        $untilNow = now()->startOfDay()->timestamp;

        $insights = Http::get("https://graph.facebook.com/{$apiVersion}/{$pageId}/insights", [
            'access_token' => $token,
            'metric' => $metrics,
            'period' => 'day',
            'since' => $since7,
            'until' => $untilNow,
        ]);

        $fbInsightsParsed = false;

        if ($insights->successful()) {
            foreach ($insights->json('data', []) as $metric) {
                // Somar todos os valores diarios do periodo para ter o total dos ultimos 7 dias
                $sum = 0;
                foreach ($metric['values'] ?? [] as $dayValue) {
                    $sum += $dayValue['value'] ?? 0;
                }
                // Tambem verificar se ha um unico valor (caso a API retorne formato antigo)
                if ($sum === 0 && isset($metric['values'][0]['value'])) {
                    $sum = $metric['values'][0]['value'];
                }

                if ($sum > 0) $fbInsightsParsed = true;

                match ($metric['name']) {
                    'page_impressions' => $data['impressions'] = $sum,
                    'page_impressions_unique' => $data['reach'] = $sum,
                    'page_engaged_users' => $data['platform_data']['engaged_users'] = $sum,
                    'page_post_engagements' => $data['engagement'] = $sum,
                    'page_fan_adds' => $data['followers_gained'] = $sum,
                    'page_fan_removes' => $data['followers_lost'] = $sum,
                    'page_views_total' => $data['platform_data']['page_views'] = $sum,
                    'page_actions_post_reactions_like_total' => $data['likes'] = $sum,
                    default => null,
                };
            }
        }

        if (!$fbInsightsParsed) {
            SystemLog::warning('social', 'insights.fb.reach_empty', "Facebook page insights retornou vazio para reach/impressions", [
                'account_id' => $account->id,
                'page_id' => $pageId,
                'api_status' => $insights->status(),
                'api_error' => $insights->json('error') ?? null,
                'data_count' => count($insights->json('data', [])),
            ]);
        }

        // Net followers
        if (isset($data['followers_gained']) && isset($data['followers_lost'])) {
            $data['net_followers'] = $data['followers_gained'] - $data['followers_lost'];
        }

        // 3. Posts recentes para engajamento
        $feed = Http::get("https://graph.facebook.com/{$apiVersion}/{$pageId}/posts", [
            'access_token' => $token,
            'fields' => 'id,created_time,shares,comments.summary(true),reactions.summary(true)',
            'limit' => 25,
        ]);

        $totalComments = 0;
        $totalShares = 0;

        if ($feed->successful()) {
            foreach ($feed->json('data', []) as $post) {
                $postDate = Carbon::parse($post['created_time']);
                if ($postDate->gte(now()->subDays(30))) {
                    $totalComments += $post['comments']['summary']['total_count'] ?? 0;
                    $totalShares += $post['shares']['count'] ?? 0;
                }
            }
        }

        $data['comments'] = $totalComments;
        $data['shares'] = $totalShares;

        // 4. Video views
        $videos = Http::get("https://graph.facebook.com/{$apiVersion}/{$pageId}/insights", [
            'access_token' => $token,
            'metric' => 'page_video_views',
            'period' => 'day',
            'since' => now()->subDay()->startOfDay()->timestamp,
            'until' => now()->startOfDay()->timestamp,
        ]);

        if ($videos->successful()) {
            foreach ($videos->json('data', []) as $metric) {
                if ($metric['name'] === 'page_video_views') {
                    $data['video_views'] = $metric['values'][0]['value'] ?? null;
                }
            }
        }

        return $data;
    }

    // ================================================================
    // YOUTUBE INSIGHTS (YouTube Analytics API)
    // ================================================================

    private function fetchYoutubeInsights(SocialAccount $account): array
    {
        $token = $account->access_token;
        $channelId = $account->platform_user_id;

        $data = [
            'followers_count' => null,
            'posts_count' => null,
            'video_views' => null,
            'likes' => null,
            'comments' => null,
            'platform_data' => [],
        ];

        // 1. Channel statistics
        $channel = Http::withToken($token)->get('https://www.googleapis.com/youtube/v3/channels', [
            'part' => 'statistics,snippet',
            'id' => $channelId,
        ]);

        if ($channel->successful()) {
            $stats = $channel->json('items.0.statistics', []);
            $data['followers_count'] = (int) ($stats['subscriberCount'] ?? 0);
            $data['posts_count'] = (int) ($stats['videoCount'] ?? 0);
            $data['video_views'] = (int) ($stats['viewCount'] ?? 0);
            $data['platform_data']['total_views'] = (int) ($stats['viewCount'] ?? 0);
        }

        // 2. YouTube Analytics (ultimas 24h) - requer youtube.readonly scope
        $yesterday = now()->subDay()->format('Y-m-d');
        $today = now()->format('Y-m-d');

        $analytics = Http::withToken($token)->get('https://youtubeanalytics.googleapis.com/v2/reports', [
            'ids' => 'channel==' . $channelId,
            'startDate' => $yesterday,
            'endDate' => $today,
            'metrics' => 'views,likes,dislikes,comments,shares,subscribersGained,subscribersLost,estimatedMinutesWatched,averageViewDuration',
            'dimensions' => 'day',
        ]);

        if ($analytics->successful()) {
            $rows = $analytics->json('rows', []);
            if (!empty($rows)) {
                $row = $rows[0]; // Dados do dia
                $data['platform_data']['daily_views'] = $row[1] ?? 0;
                $data['likes'] = $row[2] ?? 0;
                $data['platform_data']['dislikes'] = $row[3] ?? 0;
                $data['comments'] = $row[4] ?? 0;
                $data['shares'] = $row[5] ?? 0;
                $data['followers_gained'] = $row[6] ?? 0;
                $data['followers_lost'] = $row[7] ?? 0;
                $data['net_followers'] = ($row[6] ?? 0) - ($row[7] ?? 0);
                $data['platform_data']['watch_time_minutes'] = $row[8] ?? 0;
                $data['platform_data']['avg_view_duration'] = $row[9] ?? 0;
            }
        }

        return $data;
    }

    // ================================================================
    // TIKTOK INSIGHTS
    // ================================================================

    private function fetchTiktokInsights(SocialAccount $account): array
    {
        $token = $account->access_token;

        $data = [
            'followers_count' => null,
            'following_count' => null,
            'posts_count' => null,
            'likes' => null,
            'video_views' => null,
            'platform_data' => [],
        ];

        // User info atualizado
        $user = Http::withToken($token)->get('https://open.tiktokapis.com/v2/user/info/', [
            'fields' => 'open_id,avatar_url,display_name,username,follower_count,following_count,likes_count,video_count',
        ]);

        if ($user->successful()) {
            $u = $user->json('data.user', []);
            $data['followers_count'] = $u['follower_count'] ?? null;
            $data['following_count'] = $u['following_count'] ?? null;
            $data['likes'] = $u['likes_count'] ?? null;
            $data['posts_count'] = $u['video_count'] ?? null;
        }

        // Video list para engagement recente
        $videos = Http::withToken($token)->post('https://open.tiktokapis.com/v2/video/list/', [
            'max_count' => 20,
        ]);

        if ($videos->successful()) {
            $totalViews = 0;
            foreach ($videos->json('data.videos', []) as $video) {
                $totalViews += $video['view_count'] ?? 0;
            }
            $data['video_views'] = $totalViews;
        }

        return $data;
    }

    // ================================================================
    // LINKEDIN INSIGHTS
    // ================================================================

    private function fetchLinkedinInsights(SocialAccount $account): array
    {
        $token = $account->access_token;
        $orgId = $account->platform_user_id;
        $type = $account->metadata['type'] ?? 'profile';

        $data = [
            'followers_count' => null,
            'impressions' => null,
            'engagement' => null,
            'clicks' => null,
            'platform_data' => [],
        ];

        if ($type === 'organization') {
            // Follower statistics
            $followers = Http::withToken($token)->get("https://api.linkedin.com/v2/organizationalEntityFollowerStatistics", [
                'q' => 'organizationalEntity',
                'organizationalEntity' => "urn:li:organization:{$orgId}",
            ]);

            if ($followers->successful()) {
                $elements = $followers->json('elements', []);
                if (!empty($elements)) {
                    $data['followers_count'] = $elements[0]['followerGains']['organicFollowerCount'] ?? null;
                    $data['platform_data']['paid_followers'] = $elements[0]['followerGains']['paidFollowerCount'] ?? null;
                }
            }

            // Page statistics
            $pageStats = Http::withToken($token)->get("https://api.linkedin.com/v2/organizationPageStatistics", [
                'q' => 'organization',
                'organization' => "urn:li:organization:{$orgId}",
            ]);

            if ($pageStats->successful()) {
                $elements = $pageStats->json('elements', []);
                if (!empty($elements)) {
                    $views = $elements[0]['totalPageStatistics']['views'] ?? [];
                    $data['platform_data']['page_views'] = $views['allPageViews']['pageViews'] ?? 0;
                    $data['platform_data']['unique_visitors'] = $views['allPageViews']['uniquePageViews'] ?? 0;
                }
            }

            // Share statistics
            $shares = Http::withToken($token)->get("https://api.linkedin.com/v2/organizationalEntityShareStatistics", [
                'q' => 'organizationalEntity',
                'organizationalEntity' => "urn:li:organization:{$orgId}",
            ]);

            if ($shares->successful()) {
                $elements = $shares->json('elements', []);
                if (!empty($elements)) {
                    $totals = $elements[0]['totalShareStatistics'] ?? [];
                    $data['impressions'] = $totals['impressionCount'] ?? null;
                    $data['engagement'] = $totals['engagement'] ?? null;
                    $data['clicks'] = $totals['clickCount'] ?? null;
                    $data['likes'] = $totals['likeCount'] ?? null;
                    $data['comments'] = $totals['commentCount'] ?? null;
                    $data['shares'] = $totals['shareCount'] ?? null;
                }
            }
        }

        return $data;
    }

    // ================================================================
    // PINTEREST INSIGHTS
    // ================================================================

    private function fetchPinterestInsights(SocialAccount $account): array
    {
        $token = $account->access_token;

        $data = [
            'followers_count' => null,
            'posts_count' => null,
            'impressions' => null,
            'saves' => null,
            'clicks' => null,
            'platform_data' => [],
        ];

        // User account
        $user = Http::withToken($token)->get('https://api.pinterest.com/v5/user_account');

        if ($user->successful()) {
            $u = $user->json();
            $data['followers_count'] = $u['follower_count'] ?? null;
            $data['posts_count'] = $u['pin_count'] ?? null;
        }

        // Analytics (ultimos 30 dias)
        $analytics = Http::withToken($token)->get('https://api.pinterest.com/v5/user_account/analytics', [
            'start_date' => now()->subDays(1)->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'metric_types' => 'IMPRESSION,SAVE,PIN_CLICK,OUTBOUND_CLICK',
        ]);

        if ($analytics->successful()) {
            $totals = $analytics->json('all.daily_metrics.0', []);
            $data['impressions'] = $totals['IMPRESSION'] ?? null;
            $data['saves'] = $totals['SAVE'] ?? null;
            $data['clicks'] = ($totals['PIN_CLICK'] ?? 0) + ($totals['OUTBOUND_CLICK'] ?? 0);
            $data['platform_data']['pin_clicks'] = $totals['PIN_CLICK'] ?? 0;
            $data['platform_data']['outbound_clicks'] = $totals['OUTBOUND_CLICK'] ?? 0;
        }

        return $data;
    }
}

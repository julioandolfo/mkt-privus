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
                'engagement' => $data['engagement'] ?? null,
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
        }

        // 2. Insights do perfil (ultimos 28 dias = period day, since/until)
        // Metricas de conta Instagram Business
        $insightMetrics = 'impressions,reach,profile_views,website_clicks,email_contacts,phone_call_clicks,text_message_clicks,get_directions_clicks';

        $insights = Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/insights", [
            'access_token' => $token,
            'metric' => $insightMetrics,
            'period' => 'day',
            'since' => now()->subDay()->startOfDay()->timestamp,
            'until' => now()->startOfDay()->timestamp,
        ]);

        if ($insights->successful()) {
            foreach ($insights->json('data', []) as $metric) {
                $value = $metric['values'][0]['value'] ?? 0;
                $name = $metric['name'];

                match ($name) {
                    'impressions' => $data['impressions'] = $value,
                    'reach' => $data['reach'] = $value,
                    'profile_views' => $data['platform_data']['profile_views'] = $value,
                    'website_clicks' => $data['clicks'] = $value,
                    'email_contacts' => $data['platform_data']['email_contacts'] = $value,
                    'phone_call_clicks' => $data['platform_data']['phone_calls'] = $value,
                    'text_message_clicks' => $data['platform_data']['text_messages'] = $value,
                    'get_directions_clicks' => $data['platform_data']['directions_clicks'] = $value,
                    default => null,
                };
            }
        }

        // 3. Engajamento dos posts recentes (ultimos 25 posts)
        $media = Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/media", [
            'access_token' => $token,
            'fields' => 'id,timestamp,like_count,comments_count',
            'limit' => 25,
        ]);

        $totalLikes = 0;
        $totalComments = 0;
        $recentPostIds = [];

        if ($media->successful()) {
            foreach ($media->json('data', []) as $post) {
                // Considerar apenas posts do ultimo mes
                $postDate = Carbon::parse($post['timestamp']);
                if ($postDate->gte(now()->subDays(30))) {
                    $totalLikes += $post['like_count'] ?? 0;
                    $totalComments += $post['comments_count'] ?? 0;
                    $recentPostIds[] = $post['id'];
                }
            }
        }

        $data['likes'] = $totalLikes;
        $data['comments'] = $totalComments;

        // 4. Insights detalhados dos posts (saves, shares, video_views)
        $totalSaves = 0;
        $totalShares = 0;
        $totalVideoViews = 0;

        foreach (array_slice($recentPostIds, 0, 10) as $mediaId) {
            $mediaInsights = Http::get("https://graph.facebook.com/{$apiVersion}/{$mediaId}/insights", [
                'access_token' => $token,
                'metric' => 'saved,shares,video_views,reach,impressions',
            ]);

            if ($mediaInsights->successful()) {
                foreach ($mediaInsights->json('data', []) as $mi) {
                    match ($mi['name']) {
                        'saved' => $totalSaves += $mi['values'][0]['value'] ?? 0,
                        'shares' => $totalShares += $mi['values'][0]['value'] ?? 0,
                        'video_views' => $totalVideoViews += $mi['values'][0]['value'] ?? 0,
                        default => null,
                    };
                }
            }
        }

        $data['saves'] = $totalSaves;
        $data['shares'] = $totalShares;
        $data['video_views'] = $totalVideoViews > 0 ? $totalVideoViews : null;
        $data['engagement'] = $totalLikes + $totalComments + $totalSaves + $totalShares;

        // 5. Audience demographics (se disponivel)
        $audienceInsights = Http::get("https://graph.facebook.com/{$apiVersion}/{$igUserId}/insights", [
            'access_token' => $token,
            'metric' => 'audience_gender_age,audience_city,audience_country',
            'period' => 'lifetime',
        ]);

        if ($audienceInsights->successful()) {
            foreach ($audienceInsights->json('data', []) as $metric) {
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

        return $data;
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

        // 2. Page Insights (daily)
        $metrics = 'page_impressions,page_impressions_unique,page_engaged_users,page_post_engagements,page_fan_adds,page_fan_removes,page_views_total,page_actions_post_reactions_like_total,page_actions_post_reactions_love_total';

        $insights = Http::get("https://graph.facebook.com/{$apiVersion}/{$pageId}/insights", [
            'access_token' => $token,
            'metric' => $metrics,
            'period' => 'day',
            'since' => now()->subDay()->startOfDay()->timestamp,
            'until' => now()->startOfDay()->timestamp,
        ]);

        if ($insights->successful()) {
            foreach ($insights->json('data', []) as $metric) {
                $value = $metric['values'][0]['value'] ?? 0;

                match ($metric['name']) {
                    'page_impressions' => $data['impressions'] = $value,
                    'page_impressions_unique' => $data['reach'] = $value,
                    'page_engaged_users' => $data['platform_data']['engaged_users'] = $value,
                    'page_post_engagements' => $data['engagement'] = $value,
                    'page_fan_adds' => $data['followers_gained'] = $value,
                    'page_fan_removes' => $data['followers_lost'] = $value,
                    'page_views_total' => $data['platform_data']['page_views'] = $value,
                    'page_actions_post_reactions_like_total' => $data['likes'] = $value,
                    default => null,
                };
            }
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

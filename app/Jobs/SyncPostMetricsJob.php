<?php

namespace App\Jobs;

use App\Models\PostSchedule;
use App\Models\SocialAccount;
use App\Models\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncPostMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;
    public int $backoff = 60;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $schedules = PostSchedule::where('status', 'published')
            ->whereNotNull('platform_post_id')
            ->where(function ($q) {
                $q->whereNull('metrics_synced_at')
                  ->orWhere('metrics_synced_at', '<', now()->subHours(6));
            })
            ->where('published_at', '>=', now()->subDays(90))
            ->with('socialAccount')
            ->limit(100)
            ->get();

        if ($schedules->isEmpty()) {
            return;
        }

        $synced = 0;
        $failed = 0;
        $tokenCache = [];

        foreach ($schedules as $schedule) {
            try {
                $account = $schedule->socialAccount;
                if (!$account || !$account->is_active) {
                    continue;
                }

                $platform = $account->platform->value ?? $account->platform;

                if (!isset($tokenCache[$account->id])) {
                    $tokenCache[$account->id] = $account->getFreshToken();
                }
                $token = $tokenCache[$account->id];

                if (!$token) {
                    continue;
                }

                $metrics = match ($platform) {
                    'instagram' => $this->fetchInstagramMetrics($schedule, $token),
                    'facebook' => $this->fetchFacebookMetrics($schedule, $token),
                    default => null,
                };

                if ($metrics) {
                    $schedule->update(array_merge($metrics, [
                        'metrics_synced_at' => now(),
                    ]));
                    $synced++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning("SyncPostMetrics: failed for schedule #{$schedule->id}: {$e->getMessage()}");
            }
        }

        if ($synced > 0 || $failed > 0) {
            SystemLog::info('social', 'post_metrics.sync', "Métricas de posts sincronizadas: {$synced} OK, {$failed} falhas", [
                'synced' => $synced,
                'failed' => $failed,
                'total_checked' => $schedules->count(),
            ]);
        }
    }

    private function fetchInstagramMetrics(PostSchedule $schedule, string $token): ?array
    {
        $mediaId = $schedule->platform_post_id;
        $apiVersion = 'v21.0';

        $basic = Http::get("https://graph.facebook.com/{$apiVersion}/{$mediaId}", [
            'access_token' => $token,
            'fields' => 'like_count,comments_count,media_type,media_product_type,timestamp',
        ]);

        if (!$basic->successful()) {
            return null;
        }

        $data = $basic->json();
        $metrics = [
            'likes' => $data['like_count'] ?? 0,
            'comments' => $data['comments_count'] ?? 0,
            'shares' => 0,
            'saves' => 0,
            'reach' => 0,
            'impressions' => 0,
            'video_views' => 0,
        ];

        $insights = Http::get("https://graph.facebook.com/{$apiVersion}/{$mediaId}/insights", [
            'access_token' => $token,
            'metric' => 'saved,shares,reach,total_interactions,views',
        ]);

        if ($insights->successful() && !empty($insights->json('data'))) {
            foreach ($insights->json('data', []) as $metric) {
                $name = $metric['name'] ?? '';
                $value = $this->extractInsightValue($metric);

                match ($name) {
                    'saved' => $metrics['saves'] = $value,
                    'shares' => $metrics['shares'] = $value,
                    'reach' => $metrics['reach'] = $value,
                    'views' => $metrics['impressions'] = $value,
                    'total_interactions' => null,
                    default => null,
                };
            }
        } else {
            $legacyInsights = Http::get("https://graph.facebook.com/{$apiVersion}/{$mediaId}/insights", [
                'access_token' => $token,
                'metric' => 'total_interactions,reach',
            ]);

            if ($legacyInsights->successful() && !empty($legacyInsights->json('data'))) {
                foreach ($legacyInsights->json('data', []) as $metric) {
                    $name = $metric['name'] ?? '';
                    $value = $this->extractInsightValue($metric);

                    if ($name === 'reach') $metrics['reach'] = $value;
                }
            }
        }

        $mediaType = $data['media_product_type'] ?? $data['media_type'] ?? '';
        if (in_array(strtolower($mediaType), ['reels', 'video'])) {
            $metrics['video_views'] = $metrics['impressions'];
        }

        $totalEngagement = $metrics['likes'] + $metrics['comments'] + $metrics['shares'] + $metrics['saves'];
        $metrics['engagement_rate'] = $metrics['reach'] > 0
            ? round(($totalEngagement / $metrics['reach']) * 100, 2)
            : 0;

        return $metrics;
    }

    private function fetchFacebookMetrics(PostSchedule $schedule, string $token): ?array
    {
        $postId = $schedule->platform_post_id;
        $apiVersion = 'v21.0';

        $basic = Http::get("https://graph.facebook.com/{$apiVersion}/{$postId}", [
            'access_token' => $token,
            'fields' => 'likes.summary(true),comments.summary(true),shares',
        ]);

        if (!$basic->successful()) {
            return null;
        }

        $data = $basic->json();

        $metrics = [
            'likes' => $data['likes']['summary']['total_count'] ?? 0,
            'comments' => $data['comments']['summary']['total_count'] ?? 0,
            'shares' => $data['shares']['count'] ?? 0,
            'saves' => 0,
            'reach' => 0,
            'impressions' => 0,
            'video_views' => 0,
        ];

        $insights = Http::get("https://graph.facebook.com/{$apiVersion}/{$postId}/insights", [
            'access_token' => $token,
            'metric' => 'post_impressions,post_impressions_unique',
        ]);

        if ($insights->successful() && !empty($insights->json('data'))) {
            foreach ($insights->json('data', []) as $metric) {
                $name = $metric['name'] ?? '';
                $value = $this->extractInsightValue($metric);

                if ($name === 'post_impressions') $metrics['impressions'] = $value;
                if ($name === 'post_impressions_unique') $metrics['reach'] = $value;
            }
        }

        $totalEngagement = $metrics['likes'] + $metrics['comments'] + $metrics['shares'];
        $metrics['engagement_rate'] = $metrics['reach'] > 0
            ? round(($totalEngagement / $metrics['reach']) * 100, 2)
            : 0;

        return $metrics;
    }

    private function extractInsightValue(array $metric): int
    {
        if (isset($metric['total_value']['value'])) {
            return (int) $metric['total_value']['value'];
        }

        if (isset($metric['values'])) {
            $sum = 0;
            foreach ($metric['values'] as $v) {
                $sum += (int) ($v['value'] ?? 0);
            }
            return $sum ?: 0;
        }

        return 0;
    }
}

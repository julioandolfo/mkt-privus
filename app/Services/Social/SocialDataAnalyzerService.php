<?php

namespace App\Services\Social;

use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialInsight;
use App\Models\SystemLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Serviço de análise de dados sociais para enriquecer o Content Engine
 * Analisa contas conectadas, posts históricos e insights para tomar decisões inteligentes
 */
class SocialDataAnalyzerService
{
    /**
     * Analisa dados sociais da marca e retorna insights para o Content Engine
     */
    public function analyzeSocialData(Brand $brand): array
    {
        SystemLog::info('content_engine', 'social_data.analysis.started', "Iniciando análise de dados sociais", [
            'brand_id' => $brand->id,
            'brand_name' => $brand->name,
        ]);

        // 1. Busca contas sociais ativas
        $accounts = $this->getActiveAccounts($brand);

        if ($accounts->isEmpty()) {
            SystemLog::info('content_engine', 'social_data.no_accounts', "Nenhuma conta social ativa encontrada", [
                'brand_id' => $brand->id,
            ]);
            return $this->getDefaultSocialData();
        }

        // 2. Análise de performance por plataforma
        $platformPerformance = $this->analyzePlatformPerformance($brand, $accounts);

        // 3. Análise de posts históricos
        $postAnalysis = $this->analyzeHistoricalPosts($brand);

        // 4. Melhores horários baseados em dados
        $bestTimes = $this->calculateBestTimes($brand);

        // 5. Análise de audiência
        $audienceInsights = $this->analyzeAudience($accounts);

        $result = [
            'has_social_data' => true,
            'accounts_summary' => $accounts->map(fn($a) => [
                'platform' => $a->platform->value ?? $a->platform,
                'username' => $a->username,
                'followers' => $a->metadata['followers_count'] ?? null,
            ])->toArray(),
            'platform_performance' => $platformPerformance,
            'post_insights' => $postAnalysis,
            'optimal_schedule' => $bestTimes,
            'audience_insights' => $audienceInsights,
            'content_recommendations' => $this->generateContentRecommendations($postAnalysis, $platformPerformance),
            '_analyzed_at' => now()->toIso8601String(),
        ];

        SystemLog::info('content_engine', 'social_data.analysis.completed', "Análise de dados sociais concluída", [
            'brand_id' => $brand->id,
            'accounts_count' => $accounts->count(),
            'platforms' => $accounts->pluck('platform')->toArray(),
        ]);

        return $result;
    }

    /**
     * Busca contas sociais ativas da marca
     */
    private function getActiveAccounts(Brand $brand): Collection
    {
        return $brand->socialAccounts()
            ->where('is_active', true)
            ->whereNotNull('access_token')
            ->get();
    }

    /**
     * Analisa performance por plataforma baseada em insights recentes
     */
    private function analyzePlatformPerformance(Brand $brand, Collection $accounts): array
    {
        $performance = [];

        foreach ($accounts as $account) {
            $platform = $account->platform->value ?? $account->platform;

            // Busca insights dos últimos 30 dias
            $insights = SocialInsight::where('social_account_id', $account->id)
                ->where('date', '>=', now()->subDays(30))
                ->orderBy('date', 'desc')
                ->get();

            if ($insights->isEmpty()) {
                continue;
            }

            // Calcula médias
            $avgEngagementRate = $insights->avg('engagement_rate') ?? 0;
            $avgReach = $insights->avg('reach') ?? 0;
            $avgImpressions = $insights->avg('impressions') ?? 0;
            $totalEngagement = $insights->sum('engagement') ?? 0;

            // Tendência (últimos 7 vs 7 dias anteriores)
            $last7Days = $insights->where('date', '>=', now()->subDays(7))->avg('engagement_rate') ?? 0;
            $previous7Days = $insights->whereBetween('date', [now()->subDays(14), now()->subDays(7)])->avg('engagement_rate') ?? 0;
            $trend = $previous7Days > 0 ? (($last7Days - $previous7Days) / $previous7Days) * 100 : 0;

            $performance[$platform] = [
                'engagement_rate_avg' => round($avgEngagementRate * 100, 2), // em porcentagem
                'reach_avg' => round($avgReach),
                'impressions_avg' => round($avgImpressions),
                'total_engagement_30d' => $totalEngagement,
                'trend_7d' => round($trend, 1), // positivo = crescendo
                'followers' => $account->metadata['followers_count'] ?? null,
                'account_status' => 'active',
            ];
        }

        return $performance;
    }

    /**
     * Analisa posts históricos para identificar padrões de sucesso
     */
    private function analyzeHistoricalPosts(Brand $brand): array
    {
        // Busca posts publicados nos últimos 90 dias
        $posts = Post::where('brand_id', $brand->id)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '>=', now()->subDays(90))
            ->orderBy('published_at', 'desc')
            ->get();

        if ($posts->isEmpty()) {
            return [
                'has_history' => false,
                'total_posts' => 0,
            ];
        }

        // Análise por plataforma
        $byPlatform = [];
        foreach ($posts as $post) {
            $platforms = is_array($post->platforms) ? $post->platforms : [];
            foreach ($platforms as $platform) {
                if (!isset($byPlatform[$platform])) {
                    $byPlatform[$platform] = [
                        'count' => 0,
                        'types' => [],
                        'hashtags' => [],
                        'avg_caption_length' => [],
                    ];
                }
                $byPlatform[$platform]['count']++;
                $byPlatform[$platform]['types'][] = $post->type;
                $byPlatform[$platform]['avg_caption_length'][] = mb_strlen($post->caption ?? '');

                // Extrai hashtags
                if (is_array($post->hashtags)) {
                    $byPlatform[$platform]['hashtags'] = array_merge(
                        $byPlatform[$platform]['hashtags'],
                        $post->hashtags
                    );
                }
            }
        }

        // Processa dados por plataforma
        $platformStats = [];
        foreach ($byPlatform as $platform => $data) {
            $typeCounts = array_count_values($data['types']);
            arsort($typeCounts);

            $hashtagCounts = array_count_values($data['hashtags']);
            arsort($hashtagCounts);

            $platformStats[$platform] = [
                'total_posts' => $data['count'],
                'most_used_type' => array_key_first($typeCounts) ?? 'feed',
                'type_distribution' => $typeCounts,
                'avg_caption_length' => round(array_sum($data['avg_caption_length']) / count($data['avg_caption_length'])),
                'top_hashtags' => array_slice(array_keys($hashtagCounts), 0, 5),
            ];
        }

        // Análise de conteúdo (categorias de texto)
        $contentCategories = $this->categorizeContent($posts);

        return [
            'has_history' => true,
            'total_posts' => $posts->count(),
            'period_days' => 90,
            'by_platform' => $platformStats,
            'content_categories' => $contentCategories,
            'posting_frequency' => $this->calculatePostingFrequency($posts),
        ];
    }

    /**
     * Calcula melhores horários baseados em posts anteriores
     */
    private function calculateBestTimes(Brand $brand): array
    {
        $posts = Post::where('brand_id', $brand->id)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->whereNotNull('engagement_data')
            ->where('published_at', '>=', now()->subDays(60))
            ->get();

        if ($posts->isEmpty()) {
            return $this->getDefaultBestTimes();
        }

        // Agrupa posts por hora do dia
        $hourlyPerformance = [];
        foreach ($posts as $post) {
            $hour = $post->published_at->format('H');
            $engagement = $this->extractEngagementScore($post->engagement_data);

            if (!isset($hourlyPerformance[$hour])) {
                $hourlyPerformance[$hour] = ['total_engagement' => 0, 'count' => 0];
            }
            $hourlyPerformance[$hour]['total_engagement'] += $engagement;
            $hourlyPerformance[$hour]['count']++;
        }

        // Calcula média por hora
        $hourlyAvg = [];
        foreach ($hourlyPerformance as $hour => $data) {
            $hourlyAvg[$hour] = $data['total_engagement'] / $data['count'];
        }

        // Ordena por performance
        arsort($hourlyAvg);

        // Agrupa por dia da semana também
        $weekdayPerformance = [];
        foreach ($posts as $post) {
            $weekday = $post->published_at->format('l'); // Monday, Tuesday...
            $engagement = $this->extractEngagementScore($post->engagement_data);

            if (!isset($weekdayPerformance[$weekday])) {
                $weekdayPerformance[$weekday] = ['total_engagement' => 0, 'count' => 0];
            }
            $weekdayPerformance[$weekday]['total_engagement'] += $engagement;
            $weekdayPerformance[$weekday]['count']++;
        }

        $weekdayAvg = [];
        foreach ($weekdayPerformance as $day => $data) {
            $weekdayAvg[$day] = $data['total_engagement'] / $data['count'];
        }
        arsort($weekdayAvg);

        return [
            'based_on_data' => true,
            'posts_analyzed' => $posts->count(),
            'best_hours' => array_slice(array_keys($hourlyAvg), 0, 3),
            'best_weekdays' => array_slice(array_keys($weekdayAvg), 0, 3),
            'hourly_scores' => array_map(fn($v) => round($v, 2), $hourlyAvg),
            'weekday_scores' => array_map(fn($v) => round($v, 2), $weekdayAvg),
            'default_hours' => ['09:00', '12:00', '18:00'], // fallback
        ];
    }

    /**
     * Analisa dados de audiência das contas
     */
    private function analyzeAudience(Collection $accounts): array
    {
        $insights = [];

        foreach ($accounts as $account) {
            $latestInsight = SocialInsight::where('social_account_id', $account->id)
                ->whereNotNull('audience_gender')
                ->orWhereNotNull('audience_age')
                ->orderBy('date', 'desc')
                ->first();

            if (!$latestInsight) {
                continue;
            }

            $platform = $account->platform->value ?? $account->platform;

            $insights[$platform] = [
                'gender_distribution' => $latestInsight->audience_gender ?? null,
                'age_distribution' => $latestInsight->audience_age ?? null,
                'top_cities' => $latestInsight->audience_cities ?? null,
                'top_countries' => $latestInsight->audience_countries ?? null,
            ];
        }

        return $insights;
    }

    /**
     * Gera recomendações de conteúdo baseadas nos dados
     */
    private function generateContentRecommendations(array $postAnalysis, array $platformPerformance): array
    {
        $recommendations = [];

        // Recomendações baseadas em performance por plataforma
        foreach ($platformPerformance as $platform => $data) {
            $engagement = $data['engagement_rate_avg'] ?? 0;
            $trend = $data['trend_7d'] ?? 0;

            if ($engagement > 5) {
                $recommendations[] = "{$platform}: Alta engajamento ({$engagement}%). Manter estratégia atual.";
            } elseif ($engagement < 2) {
                $recommendations[] = "{$platform}: Engajamento baixo. Testar novos formatos de conteúdo.";
            }

            if ($trend > 10) {
                $recommendations[] = "{$platform}: Crescimento de {$trend}% na última semana. Acelerar postagens.";
            } elseif ($trend < -10) {
                $recommendations[] = "{$platform}: Queda de {$trend}%. Reavaliar conteúdo.";
            }
        }

        // Recomendações de tipo de conteúdo
        if ($postAnalysis['has_history'] ?? false) {
            foreach ($postAnalysis['by_platform'] ?? [] as $platform => $stats) {
                $topType = $stats['most_used_type'] ?? 'feed';
                $recommendations[] = "{$platform}: Formato '{$topType}' é o mais utilizado.";

                if ($stats['avg_caption_length'] > 500) {
                    $recommendations[] = "{$platform}: Legendas longas funcionam bem (média {$stats['avg_caption_length']} chars).";
                } elseif ($stats['avg_caption_length'] < 100) {
                    $recommendations[] = "{$platform}: Legendas curtas são preferidas (média {$stats['avg_caption_length']} chars).";
                }
            }
        }

        return $recommendations;
    }

    /**
     * Categoriza conteúdo dos posts
     */
    private function categorizeContent(Collection $posts): array
    {
        $categories = [
            'educational' => 0,    // posts com dicas, tutoriais
            'promotional' => 0,    // posts sobre produtos/serviços
            'engagement' => 0,     // perguntas, enquetes
            'behind_scenes' => 0,  // bastidores
            'lifestyle' => 0,      // lifestyle/cotidiano
            'other' => 0,
        ];

        foreach ($posts as $post) {
            $caption = strtolower($post->caption ?? '');

            // Keywords para categorização
            if (preg_match('/\b(dica|como|tutorial|passo|guia|aprenda)\b/', $caption)) {
                $categories['educational']++;
            } elseif (preg_match('/\b(comprar|promoção|desconto|lançamento|produto|serviço)\b/', $caption)) {
                $categories['promotional']++;
            } elseif (preg_match('/\b(comente|qual|você|o que|enquete|votação)\b/', $caption)) {
                $categories['engagement']++;
            } elseif (preg_match('/\b(bastidores|making of|por trás|preparação)\b/', $caption)) {
                $categories['behind_scenes']++;
            } elseif (preg_match('/\b(dia|vida|rotina|momento|experiência)\b/', $caption)) {
                $categories['lifestyle']++;
            } else {
                $categories['other']++;
            }
        }

        // Ordena por quantidade
        arsort($categories);

        return [
            'distribution' => $categories,
            'dominant_category' => array_key_first($categories),
        ];
    }

    /**
     * Calcula frequência de postagem
     */
    private function calculatePostingFrequency(Collection $posts): array
    {
        if ($posts->isEmpty()) {
            return ['posts_per_week' => 0, 'consistency' => 'unknown'];
        }

        $dateRange = $posts->first()->published_at->diffInDays($posts->last()->published_at) + 1;
        $postsPerDay = $posts->count() / max($dateRange, 1);
        $postsPerWeek = $postsPerDay * 7;

        return [
            'posts_per_week' => round($postsPerWeek, 1),
            'total_posts' => $posts->count(),
            'period_days' => $dateRange,
            'consistency' => $postsPerWeek >= 5 ? 'high' : ($postsPerWeek >= 2 ? 'medium' : 'low'),
        ];
    }

    /**
     * Extrai score de engajamento dos dados de engajamento
     */
    private function extractEngagementScore(?array $engagementData): float
    {
        if (!$engagementData) {
            return 0;
        }

        // Soma todas as métricas de engajamento disponíveis
        $score = 0;
        $score += $engagementData['likes'] ?? 0;
        $score += ($engagementData['comments'] ?? 0) * 2; // comentários valem mais
        $score += ($engagementData['shares'] ?? 0) * 3;  // shares valem mais ainda
        $score += $engagementData['saves'] ?? 0;

        return $score;
    }

    /**
     * Retorna dados padrão quando não há contas sociais
     */
    private function getDefaultSocialData(): array
    {
        return [
            'has_social_data' => false,
            'accounts_summary' => [],
            'platform_performance' => [],
            'post_insights' => [
                'has_history' => false,
                'total_posts' => 0,
            ],
            'optimal_schedule' => $this->getDefaultBestTimes(),
            'audience_insights' => [],
            'content_recommendations' => [
                'Conecte contas sociais para obter recomendações personalizadas.',
            ],
        ];
    }

    /**
     * Retorna melhores horários padrão
     */
    private function getDefaultBestTimes(): array
    {
        return [
            'based_on_data' => false,
            'posts_analyzed' => 0,
            'best_hours' => ['09:00', '12:00', '18:00'],
            'best_weekdays' => ['Tuesday', 'Wednesday', 'Thursday'],
            'default_hours' => ['09:00', '12:00', '18:00'],
            'note' => 'Horários padrão baseados em estudos de mercado. Conecte contas para dados personalizados.',
        ];
    }
}

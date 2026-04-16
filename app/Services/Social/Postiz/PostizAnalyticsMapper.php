<?php

namespace App\Services\Social\Postiz;

/**
 * Converte o payload de analytics retornado pelo Postiz
 * (array de {label, data:[{total, date}], percentageChange}) em um array
 * associativo compatível com as colunas da tabela `social_insights`.
 *
 * Labels são normalizadas (lowercase, snake_case) antes do match pra
 * aceitar variações entre plataformas (ex: "Profile Views" vs "profile views").
 * Labels desconhecidas vão para `platform_data` como JSON bruto — nada se perde.
 */
class PostizAnalyticsMapper
{
    /**
     * Mapa de label normalizado → coluna do social_insights.
     * Cobre as métricas mais comuns retornadas por Postiz para LinkedIn,
     * TikTok, Google My Business e demais redes.
     */
    private const LABEL_TO_COLUMN = [
        // Seguidores
        'followers' => 'followers_count',
        'followers_count' => 'followers_count',
        'subscribers' => 'followers_count',
        'fans' => 'followers_count',
        'following' => 'following_count',
        'follows' => 'following_count',

        // Conteúdo
        'posts' => 'posts_count',
        'post_count' => 'posts_count',
        'videos' => 'posts_count',
        'video_count' => 'posts_count',
        'pins' => 'posts_count',

        // Alcance
        'impressions' => 'impressions',
        'views' => 'impressions',
        'reach' => 'reach',
        'unique_views' => 'reach',

        // Engajamento
        'engagement' => 'engagement',
        'engagements' => 'engagement',
        'total_engagements' => 'engagement',
        'engagement_rate' => 'engagement_rate',

        // Interações
        'likes' => 'likes',
        'reactions' => 'likes',
        'comments' => 'comments',
        'replies' => 'comments',
        'shares' => 'shares',
        'reposts' => 'shares',
        'retweets' => 'shares',
        'saves' => 'saves',
        'bookmarks' => 'saves',
        'clicks' => 'clicks',
        'link_clicks' => 'clicks',
        'website_clicks' => 'clicks',

        // Vídeo
        'video_views' => 'video_views',
        'plays' => 'video_views',
        'watch_time' => 'video_views',
        'story_views' => 'story_views',
        'stories_views' => 'story_views',
        'reel_views' => 'reel_views',
        'reels_views' => 'reel_views',
    ];

    /**
     * Converte analytics Postiz para array pronto pra social_insights.
     *
     * Estratégia:
     *  - Pega o último valor do array `data` (mais recente) em cada label.
     *  - Se o label bater no mapa, preenche a coluna correspondente.
     *  - Senão, adiciona em platform_data['postiz'] pra não perder.
     *  - percentageChange de cada label vai em platform_data para histórico.
     *
     * @param array $analytics Array retornado por PostizGateway::getPlatformAnalytics
     * @return array<string, mixed> chaves = colunas de social_insights
     */
    public static function map(array $analytics): array
    {
        $mapped = [
            'platform_data' => [
                'postiz' => ['changes' => []],
            ],
        ];

        foreach ($analytics as $metric) {
            $label = (string) ($metric['label'] ?? '');
            $data = $metric['data'] ?? [];
            $change = $metric['percentageChange'] ?? null;

            if ($label === '' || empty($data)) {
                continue;
            }

            $latest = end($data);
            $value = self::parseNumber($latest['total'] ?? null);

            if ($value === null) {
                continue;
            }

            $key = self::normalizeLabel($label);
            $column = self::LABEL_TO_COLUMN[$key] ?? null;

            if ($column) {
                // Se a mesma coluna receber duas métricas (ex: dois aliases), mantém o maior valor
                $mapped[$column] = isset($mapped[$column]) ? max($mapped[$column], $value) : $value;
            } else {
                $mapped['platform_data']['postiz'][$key] = $value;
            }

            if ($change !== null) {
                $mapped['platform_data']['postiz']['changes'][$key] = $change;
            }
        }

        return $mapped;
    }

    private static function normalizeLabel(string $label): string
    {
        $normalized = strtolower(trim($label));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;
        return trim($normalized, '_');
    }

    private static function parseNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Postiz pode retornar "1.2K" ou "3.5M" em alguns casos — normalizar.
        if (is_string($value)) {
            $value = str_replace(',', '', trim($value));
            if (preg_match('/^(-?[\d.]+)\s*([kmb])$/i', $value, $m)) {
                $mult = ['k' => 1_000, 'm' => 1_000_000, 'b' => 1_000_000_000][strtolower($m[2])] ?? 1;
                return (float) $m[1] * $mult;
            }
        }

        return is_numeric($value) ? (float) $value : null;
    }
}

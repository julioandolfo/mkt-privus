<?php

namespace App\Services\Blog;

use App\Models\AnalyticsConnection;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Descobre conteúdo do WordPress/WooCommerce vinculado ao blog (páginas,
 * categorias do blog e — se WooCommerce — produtos) para oferecer como
 * candidatos de link interno aos artigos gerados pela IA.
 *
 * Resultado é cacheado por conexão (TTL 6h) para não bater na API a cada
 * artigo. Falhas em endpoints individuais são silenciosas: retorna o que
 * conseguiu coletar.
 */
class WordPressContentDiscoveryService
{
    private const CACHE_TTL_SECONDS = 21600; // 6h

    /**
     * Retorna candidatos no formato esperado pelo BlogArticleService:
     *   [{ label, url, type }, ...]
     *
     * Tipos possíveis: 'page', 'blog_category', 'product'.
     */
    public function fetchLinkCandidates(AnalyticsConnection $connection): array
    {
        return Cache::remember(
            "wp_link_candidates:{$connection->id}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->discover($connection),
        );
    }

    public function clearCache(AnalyticsConnection $connection): void
    {
        Cache::forget("wp_link_candidates:{$connection->id}");
    }

    private function discover(AnalyticsConnection $connection): array
    {
        $baseUrl = $this->resolveBaseUrl($connection);
        $auth    = $this->resolveAuth($connection);

        if (!$baseUrl || !$auth) {
            SystemLog::warning('blog', 'wp_discovery.skipped', "Sem URL base ou auth para descoberta — conexão #{$connection->id}", [
                'connection_id' => $connection->id,
                'has_base_url'  => (bool) $baseUrl,
                'has_auth'      => (bool) $auth,
            ]);
            return [];
        }

        $candidates = [];

        $pages = $this->fetchPages($baseUrl, $auth);
        $blogCategories = $this->fetchBlogCategories($baseUrl, $auth);
        $products = $connection->platform === 'woocommerce'
            ? $this->fetchProducts($baseUrl, $auth)
            : [];

        $candidates = array_merge($pages, $blogCategories, $products);

        SystemLog::info('blog', 'wp_discovery.complete', "Descoberta de links concluída para conexão #{$connection->id}", [
            'connection_id' => $connection->id,
            'platform'      => $connection->platform,
            'pages'         => count($pages),
            'categories'    => count($blogCategories),
            'products'      => count($products),
            'total'         => count($candidates),
        ]);

        return $candidates;
    }

    private function fetchPages(string $baseUrl, array $auth): array
    {
        try {
            $resp = Http::withBasicAuth($auth['user'], $auth['pass'])
                ->timeout(15)
                ->get("{$baseUrl}/wp-json/wp/v2/pages", [
                    'per_page' => 50,
                    'status'   => 'publish',
                    '_fields'  => 'title,link',
                ]);

            if (!$resp->successful()) {
                return [];
            }

            return collect($resp->json())
                ->map(fn ($p) => [
                    'label' => trim(html_entity_decode(strip_tags($p['title']['rendered'] ?? ''))),
                    'url'   => $p['link'] ?? '',
                    'type'  => 'page',
                ])
                ->filter(fn ($x) => $x['label'] !== '' && $x['url'] !== '')
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function fetchBlogCategories(string $baseUrl, array $auth): array
    {
        try {
            $resp = Http::withBasicAuth($auth['user'], $auth['pass'])
                ->timeout(15)
                ->get("{$baseUrl}/wp-json/wp/v2/categories", [
                    'per_page' => 50,
                    '_fields'  => 'name,link,count',
                ]);

            if (!$resp->successful()) {
                return [];
            }

            return collect($resp->json())
                ->filter(fn ($c) => ($c['count'] ?? 0) > 0)
                ->map(fn ($c) => [
                    'label' => trim(html_entity_decode((string) ($c['name'] ?? ''))),
                    'url'   => $c['link'] ?? '',
                    'type'  => 'blog_category',
                ])
                ->filter(fn ($x) => $x['label'] !== '' && $x['url'] !== '')
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function fetchProducts(string $baseUrl, array $auth): array
    {
        try {
            $resp = Http::withBasicAuth($auth['user'], $auth['pass'])
                ->timeout(20)
                ->get("{$baseUrl}/wp-json/wc/v3/products", [
                    'per_page' => 100,
                    'status'   => 'publish',
                    '_fields'  => 'name,permalink',
                ]);

            if (!$resp->successful()) {
                return [];
            }

            return collect($resp->json())
                ->map(fn ($p) => [
                    'label' => trim(html_entity_decode((string) ($p['name'] ?? ''))),
                    'url'   => $p['permalink'] ?? '',
                    'type'  => 'product',
                ])
                ->filter(fn ($x) => $x['label'] !== '' && $x['url'] !== '')
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolveBaseUrl(AnalyticsConnection $connection): ?string
    {
        $config = $connection->config ?? [];
        $url = $config['store_url'] ?? $config['site_url'] ?? $config['wordpress_url'] ?? null;
        return $url ? rtrim($url, '/') : null;
    }

    private function resolveAuth(AnalyticsConnection $connection): ?array
    {
        $config = $connection->config ?? [];

        if (!empty($config['wp_username']) && !empty($config['wp_app_password'])) {
            return [
                'user' => $config['wp_username'],
                'pass' => $config['wp_app_password'],
            ];
        }

        if (!empty($config['consumer_key']) && !empty($config['consumer_secret'])) {
            return [
                'user' => $config['consumer_key'],
                'pass' => $config['consumer_secret'],
            ];
        }

        return null;
    }
}

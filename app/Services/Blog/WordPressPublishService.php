<?php

namespace App\Services\Blog;

use App\Models\AnalyticsConnection;
use App\Models\BlogArticle;
use App\Models\BlogCategory;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class WordPressPublishService
{
    /**
     * Testa conexão com a API WordPress REST (posts)
     */
    public function testConnection(AnalyticsConnection $connection): array
    {
        $config = $connection->config ?? [];
        $baseUrl = $this->getBaseUrl($config);
        $auth = $this->getAuth($connection);

        if (!$baseUrl || !$auth) {
            return ['success' => false, 'error' => 'Configuração de conexão incompleta.'];
        }

        try {
            $response = Http::withBasicAuth($auth['user'], $auth['pass'])
                ->timeout(15)
                ->get("{$baseUrl}/wp-json/wp/v2/posts", ['per_page' => 1]);

            if ($response->successful()) {
                // Buscar info do site
                $siteResponse = Http::timeout(10)->get("{$baseUrl}/wp-json");
                $siteName = $siteResponse->successful()
                    ? ($siteResponse->json('name') ?? $baseUrl)
                    : $baseUrl;

                return [
                    'success' => true,
                    'site_name' => $siteName,
                    'site_url' => $baseUrl,
                    'can_publish' => true,
                ];
            }

            if ($response->status() === 401 || $response->status() === 403) {
                return ['success' => false, 'error' => 'Credenciais inválidas ou sem permissão para publicar posts.'];
            }

            return ['success' => false, 'error' => "Erro HTTP {$response->status()}: {$response->body()}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => "Não foi possível conectar: {$e->getMessage()}"];
        }
    }

    /**
     * Publica um artigo no WordPress
     */
    public function publish(BlogArticle $article): array
    {
        $connection = $article->wordpressConnection;
        if (!$connection) {
            return ['success' => false, 'error' => 'Artigo sem conexão WordPress definida.'];
        }

        $config = $connection->config ?? [];
        $baseUrl = $this->getBaseUrl($config);
        $auth = $this->getAuth($connection);

        if (!$baseUrl || !$auth) {
            return ['success' => false, 'error' => 'Configuração de conexão incompleta.'];
        }

        $article->update(['status' => 'publishing']);

        try {
            // 1. Upload da imagem de capa (se existir)
            $featuredMediaId = null;
            if ($article->cover_image_path) {
                $mediaResult = $this->uploadMedia($connection, $article->cover_image_path, $article->title);
                if ($mediaResult['success'] ?? false) {
                    $featuredMediaId = $mediaResult['media_id'];
                }
            }

            // 2. Resolver categoria no WordPress
            $wpCategoryId = null;
            if ($article->category && $article->category->wp_category_id) {
                $wpCategoryId = $article->category->wp_category_id;
            }

            // 3. Publicar post
            $payload = [
                'title' => $article->title,
                'content' => $article->content,
                'excerpt' => $article->excerpt ?? '',
                'status' => 'publish',
                'slug' => $article->slug,
            ];

            if ($featuredMediaId) {
                $payload['featured_media'] = $featuredMediaId;
            }

            if ($wpCategoryId) {
                $payload['categories'] = [$wpCategoryId];
            }

            if (!empty($article->tags)) {
                // Criar/resolver tags no WordPress
                $tagIds = $this->resolveWpTags($connection, $article->tags);
                if (!empty($tagIds)) {
                    $payload['tags'] = $tagIds;
                }
            }

            // Adicionar SEO via Yoast/RankMath meta (se disponível)
            $seoMeta = [];
            if ($article->meta_title) {
                $seoMeta['yoast_wpseo_title'] = $article->meta_title;
            }
            if ($article->meta_description) {
                $seoMeta['yoast_wpseo_metadesc'] = $article->meta_description;
            }
            if (!empty($seoMeta)) {
                $payload['meta'] = $seoMeta;
            }

            $response = Http::withBasicAuth($auth['user'], $auth['pass'])
                ->timeout(30)
                ->post("{$baseUrl}/wp-json/wp/v2/posts", $payload);

            if ($response->successful()) {
                $wpPost = $response->json();
                $wpPostId = $wpPost['id'] ?? null;
                $wpPostUrl = $wpPost['link'] ?? null;

                $article->update([
                    'status' => 'published',
                    'wp_post_id' => $wpPostId,
                    'wp_post_url' => $wpPostUrl,
                    'published_at' => now(),
                ]);

                SystemLog::info('blog', 'article.published', "Artigo publicado no WordPress: {$article->title}", [
                    'article_id' => $article->id,
                    'wp_post_id' => $wpPostId,
                    'wp_post_url' => $wpPostUrl,
                    'connection_id' => $connection->id,
                ]);

                return [
                    'success' => true,
                    'wp_post_id' => $wpPostId,
                    'wp_post_url' => $wpPostUrl,
                ];
            }

            $errorMsg = $response->json('message') ?? $response->body();
            $article->update(['status' => 'failed']);

            SystemLog::error('blog', 'article.publish_error', "Falha ao publicar: {$errorMsg}", [
                'article_id' => $article->id,
                'status' => $response->status(),
                'response' => substr($response->body(), 0, 500),
            ]);

            return ['success' => false, 'error' => "WordPress: {$errorMsg}"];
        } catch (\Throwable $e) {
            $article->update(['status' => 'failed']);

            SystemLog::error('blog', 'article.publish_exception', "Exceção ao publicar: {$e->getMessage()}", [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Atualiza um artigo já publicado no WordPress
     */
    public function update(BlogArticle $article): array
    {
        $connection = $article->wordpressConnection;
        if (!$connection || !$article->wp_post_id) {
            return ['success' => false, 'error' => 'Artigo sem conexão ou sem ID WordPress.'];
        }

        $config = $connection->config ?? [];
        $baseUrl = $this->getBaseUrl($config);
        $auth = $this->getAuth($connection);

        try {
            $payload = [
                'title' => $article->title,
                'content' => $article->content,
                'excerpt' => $article->excerpt ?? '',
                'slug' => $article->slug,
            ];

            $response = Http::withBasicAuth($auth['user'], $auth['pass'])
                ->timeout(30)
                ->put("{$baseUrl}/wp-json/wp/v2/posts/{$article->wp_post_id}", $payload);

            if ($response->successful()) {
                $wpPost = $response->json();
                $article->update([
                    'wp_post_url' => $wpPost['link'] ?? $article->wp_post_url,
                ]);

                return ['success' => true, 'wp_post_url' => $wpPost['link'] ?? $article->wp_post_url];
            }

            return ['success' => false, 'error' => $response->json('message') ?? $response->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Upload de mídia para o WordPress
     */
    public function uploadMedia(AnalyticsConnection $connection, string $filePath, string $title = ''): array
    {
        $config = $connection->config ?? [];
        $baseUrl = $this->getBaseUrl($config);
        $auth = $this->getAuth($connection);

        try {
            $fullPath = Storage::disk('public')->path($filePath);
            if (!file_exists($fullPath)) {
                return ['success' => false, 'error' => 'Arquivo não encontrado.'];
            }

            $mimeType = mime_content_type($fullPath) ?: 'image/png';
            $filename = basename($fullPath);

            $response = Http::withBasicAuth($auth['user'], $auth['pass'])
                ->timeout(60)
                ->withHeaders([
                    'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                    'Content-Type' => $mimeType,
                ])
                ->withBody(file_get_contents($fullPath), $mimeType)
                ->post("{$baseUrl}/wp-json/wp/v2/media");

            if ($response->successful()) {
                $media = $response->json();
                return [
                    'success' => true,
                    'media_id' => $media['id'] ?? null,
                    'media_url' => $media['source_url'] ?? null,
                ];
            }

            return ['success' => false, 'error' => $response->json('message') ?? 'Erro no upload'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Busca categorias do site WordPress
     */
    public function fetchCategories(AnalyticsConnection $connection): array
    {
        $config = $connection->config ?? [];
        $baseUrl = $this->getBaseUrl($config);
        $auth = $this->getAuth($connection);

        try {
            $response = Http::withBasicAuth($auth['user'], $auth['pass'])
                ->timeout(15)
                ->get("{$baseUrl}/wp-json/wp/v2/categories", ['per_page' => 100]);

            if ($response->successful()) {
                return collect($response->json())
                    ->map(fn($cat) => [
                        'id' => $cat['id'],
                        'name' => $cat['name'],
                        'slug' => $cat['slug'],
                        'count' => $cat['count'] ?? 0,
                        'parent' => $cat['parent'] ?? 0,
                    ])
                    ->toArray();
            }

            return [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Sincroniza categorias do WordPress para o banco local
     */
    public function syncCategories(AnalyticsConnection $connection, ?int $brandId): int
    {
        $wpCategories = $this->fetchCategories($connection);
        $synced = 0;

        foreach ($wpCategories as $wpCat) {
            BlogCategory::updateOrCreate(
                [
                    'brand_id' => $brandId,
                    'wordpress_connection_id' => $connection->id,
                    'wp_category_id' => $wpCat['id'],
                ],
                [
                    'name' => html_entity_decode($wpCat['name']),
                    'slug' => $wpCat['slug'],
                ]
            );
            $synced++;
        }

        return $synced;
    }

    // ===== PRIVATE =====

    /**
     * Obtém a URL base do site WordPress
     */
    private function getBaseUrl(array $config): ?string
    {
        $url = $config['store_url'] ?? $config['site_url'] ?? $config['wordpress_url'] ?? null;
        return $url ? rtrim($url, '/') : null;
    }

    /**
     * Obtém credenciais de autenticação
     * Suporta WooCommerce (consumer_key/secret) e WordPress puro (username/app_password)
     */
    private function getAuth(AnalyticsConnection $connection): ?array
    {
        $config = $connection->config ?? [];

        // WordPress puro: username + application password
        if (!empty($config['wp_username']) && !empty($config['wp_app_password'])) {
            return [
                'user' => $config['wp_username'],
                'pass' => $config['wp_app_password'],
            ];
        }

        // WooCommerce: consumer key + secret (funciona para WP REST API também)
        if (!empty($config['consumer_key']) && !empty($config['consumer_secret'])) {
            return [
                'user' => $config['consumer_key'],
                'pass' => $config['consumer_secret'],
            ];
        }

        return null;
    }

    /**
     * Resolve tags no WordPress (cria se não existir)
     */
    private function resolveWpTags(AnalyticsConnection $connection, array $tags): array
    {
        $config = $connection->config ?? [];
        $baseUrl = $this->getBaseUrl($config);
        $auth = $this->getAuth($connection);
        $tagIds = [];

        foreach ($tags as $tagName) {
            $tagName = trim($tagName);
            if (empty($tagName)) continue;

            try {
                // Buscar tag existente
                $response = Http::withBasicAuth($auth['user'], $auth['pass'])
                    ->timeout(10)
                    ->get("{$baseUrl}/wp-json/wp/v2/tags", ['search' => $tagName, 'per_page' => 1]);

                if ($response->successful() && !empty($response->json())) {
                    $tagIds[] = $response->json()[0]['id'];
                    continue;
                }

                // Criar nova tag
                $createResponse = Http::withBasicAuth($auth['user'], $auth['pass'])
                    ->timeout(10)
                    ->post("{$baseUrl}/wp-json/wp/v2/tags", ['name' => $tagName]);

                if ($createResponse->successful()) {
                    $tagIds[] = $createResponse->json()['id'];
                }
            } catch (\Throwable $e) {
                // Ignorar tags que falharam
                continue;
            }
        }

        return $tagIds;
    }
}

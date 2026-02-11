<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsConnection;
use App\Models\AnalyticsDataPoint;
use App\Models\Setting;
use App\Models\SystemLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleSearchConsoleService
{
    protected string $baseUrl = 'https://www.googleapis.com/webmasters/v3';
    protected string $searchUrl = 'https://searchconsole.googleapis.com/v1';

    /**
     * Gera URL de autorização OAuth para Search Console
     */
    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        $clientId = Setting::get('oauth', 'google_client_id') ?: config('social_oauth.google.client_id');

        if (empty($clientId)) {
            SystemLog::error('analytics', 'gsc.auth.no_client_id', 'Google Client ID nao configurado');
            throw new \RuntimeException('Google Client ID não configurado.');
        }

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/userinfo.profile',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return "https://accounts.google.com/o/oauth2/v2/auth?{$params}";
    }

    /**
     * Troca code por access token
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $clientId = Setting::get('oauth', 'google_client_id') ?: config('social_oauth.google.client_id');
        $clientSecret = Setting::get('oauth', 'google_client_secret') ?: config('social_oauth.google.client_secret');

        SystemLog::info('analytics', 'gsc.exchange.start', 'Trocando code por token Google Search Console');

        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if (!$response->successful()) {
            $body = $response->json();
            SystemLog::error('analytics', 'gsc.exchange.failed', 'Falha ao trocar code por token GSC', [
                'status' => $response->status(),
                'error' => $body['error'] ?? 'unknown',
                'error_description' => $body['error_description'] ?? 'N/A',
            ]);
            throw new \RuntimeException('Falha ao obter token do Google Search Console: ' . ($body['error_description'] ?? $body['error'] ?? 'HTTP ' . $response->status()));
        }

        SystemLog::info('analytics', 'gsc.exchange.success', 'Token GSC recebido');
        return $response->json();
    }

    /**
     * Refresh do access token
     */
    public function refreshToken(AnalyticsConnection $connection): string
    {
        $clientId = Setting::get('oauth', 'google_client_id') ?: config('social_oauth.google.client_id');
        $clientSecret = Setting::get('oauth', 'google_client_secret') ?: config('social_oauth.google.client_secret');

        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->successful()) {
            $body = $response->json();
            SystemLog::error('analytics', 'gsc.refresh.failed', "Falha ao renovar token GSC", [
                'connection_id' => $connection->id,
                'error' => $body['error'] ?? 'unknown',
            ]);
            throw new \RuntimeException('Falha ao renovar token do Search Console');
        }

        $data = $response->json();
        $connection->update([
            'access_token' => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return $data['access_token'];
    }

    /**
     * Busca sites verificados no Search Console
     */
    public function fetchSites(string $accessToken): array
    {
        SystemLog::info('analytics', 'gsc.sites.start', 'Buscando sites do Search Console...');

        try {
            $response = Http::withToken($accessToken)->get("{$this->baseUrl}/sites");
        } catch (\Throwable $e) {
            SystemLog::error('analytics', 'gsc.sites.request_error', "Erro HTTP ao buscar sites: {$e->getMessage()}");
            throw new \RuntimeException('Erro ao conectar com a API do Search Console: ' . $e->getMessage());
        }

        if (!$response->successful()) {
            $body = $response->json();
            SystemLog::error('analytics', 'gsc.sites.failed', 'Falha ao buscar sites do Search Console', [
                'status' => $response->status(),
                'error' => $body['error']['message'] ?? 'unknown',
                'body' => $body,
            ]);
            throw new \RuntimeException('Falha ao buscar sites do Search Console: ' . ($body['error']['message'] ?? 'HTTP ' . $response->status()));
        }

        $sites = [];
        foreach ($response->json('siteEntry', []) as $site) {
            $sites[] = [
                'id' => $site['siteUrl'],
                'name' => $site['siteUrl'],
                'permission' => $site['permissionLevel'] ?? 'unknown',
            ];
        }

        SystemLog::info('analytics', 'gsc.sites.complete', count($sites) . " site(s) encontrado(s)", [
            'count' => count($sites),
            'sites' => array_map(fn($s) => $s['name'], $sites),
        ]);

        return $sites;
    }

    /**
     * Normaliza a URL do site para o formato esperado pela API GSC
     * - URLs com protocolo devem terminar com /
     * - Domain properties (sc-domain:) ficam como estao
     */
    protected function normalizeSiteUrl(string $siteUrl): string
    {
        $siteUrl = trim($siteUrl);

        // Domain property (sc-domain:example.com) - nao precisa de /
        if (str_starts_with($siteUrl, 'sc-domain:')) {
            return $siteUrl;
        }

        // URL-prefix property - deve terminar com /
        if (preg_match('#^https?://#', $siteUrl) && !str_ends_with($siteUrl, '/')) {
            $siteUrl .= '/';
        }

        return $siteUrl;
    }

    /**
     * Constroi a URL da API com o siteUrl encodado corretamente
     */
    protected function buildApiUrl(string $siteUrl, string $path = ''): string
    {
        // A API exige que o siteUrl seja URL-encoded no path
        $encoded = urlencode($siteUrl);
        $url = "{$this->searchUrl}/sites/{$encoded}";
        if ($path) {
            $url .= '/' . ltrim($path, '/');
        }
        return $url;
    }

    /**
     * Sincroniza dados de Search Console
     */
    public function syncData(AnalyticsConnection $connection, ?string $startDate = null, ?string $endDate = null): array
    {
        $token = $this->getValidToken($connection);
        $rawSiteUrl = $connection->external_id;
        $siteUrl = $this->normalizeSiteUrl($rawSiteUrl);
        $start = $startDate ?: now()->subDays(30)->format('Y-m-d');
        $end = $endDate ?: now()->subDays(2)->format('Y-m-d');

        $connection->update(['sync_status' => 'syncing']);

        SystemLog::info('analytics', 'gsc.sync.start', "Sincronizando Search Console: {$siteUrl}", [
            'connection_id' => $connection->id,
            'raw_site_url' => $rawSiteUrl,
            'normalized_site_url' => $siteUrl,
            'encoded_url' => urlencode($siteUrl),
            'start' => $start,
            'end' => $end,
        ]);

        try {
            // Primeiro, verificar se o site existe listando os sites
            $verifyUrl = "{$this->searchUrl}/sites/" . urlencode($siteUrl);
            $verifyResponse = Http::withToken($token)->get($verifyUrl);

            if (!$verifyResponse->successful()) {
                $statusCode = $verifyResponse->status();
                $body = $verifyResponse->body();
                $isHtml = str_contains($body, '<!DOCTYPE') || str_contains($body, '<html');

                SystemLog::error('analytics', 'gsc.sync.site_not_found', "Site nao encontrado no Search Console (HTTP {$statusCode})", [
                    'connection_id' => $connection->id,
                    'site_url' => $siteUrl,
                    'verify_url' => $verifyUrl,
                    'status' => $statusCode,
                    'is_html_response' => $isHtml,
                    'body_preview' => $isHtml ? '(HTML error page)' : mb_substr($body, 0, 500),
                ]);

                // Se 404, tentar com e sem trailing slash e com sc-domain:
                if ($statusCode === 404) {
                    $alternativeUrl = $this->tryAlternativeSiteUrls($token, $rawSiteUrl);
                    if ($alternativeUrl) {
                        SystemLog::info('analytics', 'gsc.sync.alternative_found', "URL alternativa encontrada: {$alternativeUrl}", [
                            'original' => $siteUrl,
                            'alternative' => $alternativeUrl,
                        ]);
                        $siteUrl = $alternativeUrl;

                        // Atualizar o external_id da conexao para evitar o erro no futuro
                        $connection->update(['external_id' => $alternativeUrl]);
                    } else {
                        // Listar sites disponiveis para ajudar o usuario
                        $availableSites = $this->fetchSitesQuiet($token);
                        SystemLog::error('analytics', 'gsc.sync.no_site_match', "Nenhuma URL alternativa encontrada. Sites disponiveis no GSC: " . implode(', ', $availableSites), [
                            'tried_url' => $siteUrl,
                            'available_sites' => $availableSites,
                        ]);

                        throw new \RuntimeException(
                            "Site \"{$siteUrl}\" nao encontrado no Search Console. " .
                            (count($availableSites) > 0
                                ? "Sites disponíveis: " . implode(', ', $availableSites)
                                : "Nenhum site encontrado na conta. Verifique se o site está verificado no Search Console.")
                        );
                    }
                } else {
                    throw new \RuntimeException("Erro ao verificar site no Search Console (HTTP {$statusCode})");
                }
            }

            // Dados diários
            $apiUrl = $this->buildApiUrl($siteUrl, 'searchAnalytics/query');

            SystemLog::info('analytics', 'gsc.sync.query', "Buscando dados diarios GSC", [
                'api_url' => $apiUrl,
                'site_url' => $siteUrl,
            ]);

            $response = Http::withToken($token)->post($apiUrl, [
                'startDate' => $start,
                'endDate' => $end,
                'dimensions' => ['date'],
                'rowLimit' => 500,
            ]);

            if (!$response->successful()) {
                $body = $response->body();
                $isHtml = str_contains($body, '<!DOCTYPE') || str_contains($body, '<html');

                SystemLog::error('analytics', 'gsc.sync.query_failed', "Falha ao buscar dados (HTTP {$response->status()})", [
                    'status' => $response->status(),
                    'is_html' => $isHtml,
                    'body_preview' => $isHtml ? '(HTML error page)' : mb_substr($body, 0, 500),
                    'api_url' => $apiUrl,
                ]);

                throw new \RuntimeException('Erro ao buscar dados do Search Console (HTTP ' . $response->status() . ')');
            }

            $synced = 0;
            foreach ($response->json('rows', []) as $row) {
                $date = $row['keys'][0] ?? null;
                if (!$date) continue;

                $metrics = [
                    'search_impressions' => floatval($row['impressions'] ?? 0),
                    'search_clicks' => floatval($row['clicks'] ?? 0),
                    'search_ctr' => floatval($row['ctr'] ?? 0),
                    'search_position' => floatval($row['position'] ?? 0),
                ];

                foreach ($metrics as $key => $value) {
                    AnalyticsDataPoint::updateOrCreate(
                        [
                            'analytics_connection_id' => $connection->id,
                            'metric_key' => $key,
                            'date' => $date,
                            'dimension_key' => null,
                            'dimension_value' => null,
                        ],
                        [
                            'brand_id' => $connection->brand_id,
                            'platform' => 'google_search_console',
                            'value' => $value,
                        ]
                    );
                    $synced++;
                }
            }

            // Top queries
            $this->syncDimensionData($connection, $token, $siteUrl, $start, $end, 'query');
            $this->syncDimensionData($connection, $token, $siteUrl, $start, $end, 'page');
            $this->syncDimensionData($connection, $token, $siteUrl, $start, $end, 'device');
            $this->syncDimensionData($connection, $token, $siteUrl, $start, $end, 'country');

            $connection->update([
                'sync_status' => 'success',
                'last_synced_at' => now(),
                'sync_error' => null,
            ]);

            SystemLog::info('analytics', 'gsc.sync.complete', "Search Console sincronizado: {$synced} pontos para {$siteUrl}");

            return ['success' => true, 'synced' => $synced];
        } catch (\Throwable $e) {
            SystemLog::error('analytics', 'gsc.sync.error', "Erro ao sincronizar Search Console: {$e->getMessage()}", [
                'connection_id' => $connection->id,
                'site_url' => $siteUrl,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            $connection->update([
                'sync_status' => 'error',
                'sync_error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Tenta URLs alternativas para encontrar o site no GSC
     */
    protected function tryAlternativeSiteUrls(string $token, string $originalUrl): ?string
    {
        $candidates = [];
        $clean = trim($originalUrl);

        // Gerar candidatos baseado na URL original
        if (str_starts_with($clean, 'sc-domain:')) {
            $domain = str_replace('sc-domain:', '', $clean);
            $candidates = [
                "https://{$domain}/",
                "http://{$domain}/",
                "https://www.{$domain}/",
                $clean,
            ];
        } elseif (preg_match('#^https?://#', $clean)) {
            // Extrair dominio
            $parsed = parse_url($clean);
            $domain = $parsed['host'] ?? '';

            // Remover www. para dominio base
            $baseDomain = preg_replace('/^www\./', '', $domain);

            $candidates = [
                rtrim($clean, '/') . '/',  // com trailing slash
                rtrim($clean, '/'),         // sem trailing slash
                "sc-domain:{$baseDomain}",  // domain property
                "sc-domain:{$domain}",      // domain com www
                "https://{$domain}/",
                "http://{$domain}/",
            ];
        } else {
            // Provavelmente so um dominio
            $candidates = [
                "sc-domain:{$clean}",
                "https://{$clean}/",
                "http://{$clean}/",
                "https://www.{$clean}/",
            ];
        }

        // Remover duplicatas e o original
        $candidates = array_unique($candidates);

        foreach ($candidates as $candidate) {
            if ($candidate === $this->normalizeSiteUrl($originalUrl)) continue;

            $url = "{$this->searchUrl}/sites/" . urlencode($candidate);
            try {
                $response = Http::withToken($token)->timeout(10)->get($url);
                if ($response->successful()) {
                    return $candidate;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Busca lista de sites silenciosamente (para logging/diagnostico)
     */
    protected function fetchSitesQuiet(string $token): array
    {
        try {
            $response = Http::withToken($token)->timeout(10)->get("{$this->baseUrl}/sites");
            if ($response->successful()) {
                return array_map(
                    fn($s) => $s['siteUrl'] ?? '(unknown)',
                    $response->json('siteEntry', [])
                );
            }
        } catch (\Throwable) {}
        return [];
    }

    /**
     * Busca dados por dimensão específica
     */
    protected function syncDimensionData(
        AnalyticsConnection $connection,
        string $token,
        string $siteUrl,
        string $start,
        string $end,
        string $dimension
    ): void {
        $apiUrl = $this->buildApiUrl($siteUrl, 'searchAnalytics/query');

        $response = Http::withToken($token)->post($apiUrl, [
            'startDate' => $start,
            'endDate' => $end,
            'dimensions' => [$dimension],
            'rowLimit' => 25,
        ]);

        if (!$response->successful()) {
            SystemLog::warning('analytics', 'gsc.dimension.failed', "Falha ao buscar dimensao '{$dimension}' (HTTP {$response->status()})", [
                'connection_id' => $connection->id,
                'dimension' => $dimension,
            ]);
            return;
        }

        foreach ($response->json('rows', []) as $row) {
            $dimValue = $row['keys'][0] ?? '(not set)';

            AnalyticsDataPoint::updateOrCreate(
                [
                    'analytics_connection_id' => $connection->id,
                    'metric_key' => 'search_clicks',
                    'date' => $end,
                    'dimension_key' => $dimension,
                    'dimension_value' => $dimValue,
                ],
                [
                    'brand_id' => $connection->brand_id,
                    'platform' => 'google_search_console',
                    'value' => floatval($row['clicks'] ?? 0),
                    'extra' => [
                        'impressions' => floatval($row['impressions'] ?? 0),
                        'ctr' => floatval($row['ctr'] ?? 0),
                        'position' => floatval($row['position'] ?? 0),
                    ],
                ]
            );
        }
    }

    protected function getValidToken(AnalyticsConnection $connection): string
    {
        if ($connection->isTokenExpired()) {
            return $this->refreshToken($connection);
        }
        return $connection->access_token;
    }
}

<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsConnection;
use App\Models\AnalyticsDataPoint;
use App\Models\Setting;
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
     * Troca code por access token (mesma lógica do Google OAuth)
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $clientId = Setting::get('oauth', 'google_client_id') ?: config('social_oauth.google.client_id');
        $clientSecret = Setting::get('oauth', 'google_client_secret') ?: config('social_oauth.google.client_secret');

        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Falha ao obter token do Google Search Console');
        }

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
            throw new \RuntimeException('Falha ao renovar token');
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
        $response = Http::withToken($accessToken)->get("{$this->baseUrl}/sites");

        if (!$response->successful()) {
            Log::error('Search Console fetch sites error', ['response' => $response->json()]);
            return [];
        }

        $sites = [];
        foreach ($response->json('siteEntry', []) as $site) {
            $sites[] = [
                'id' => $site['siteUrl'],
                'name' => $site['siteUrl'],
                'permission' => $site['permissionLevel'] ?? 'unknown',
            ];
        }

        return $sites;
    }

    /**
     * Sincroniza dados de Search Console
     */
    public function syncData(AnalyticsConnection $connection, ?string $startDate = null, ?string $endDate = null): array
    {
        $token = $this->getValidToken($connection);
        $siteUrl = $connection->external_id;
        $start = $startDate ?: now()->subDays(30)->format('Y-m-d');
        $end = $endDate ?: now()->subDays(2)->format('Y-m-d'); // SC tem delay de ~2 dias

        $connection->update(['sync_status' => 'syncing']);

        try {
            // Dados diários
            $response = Http::withToken($token)->post(
                "{$this->searchUrl}/sites/" . urlencode($siteUrl) . "/searchAnalytics/query",
                [
                    'startDate' => $start,
                    'endDate' => $end,
                    'dimensions' => ['date'],
                    'rowLimit' => 500,
                ]
            );

            if (!$response->successful()) {
                throw new \RuntimeException('Erro ao buscar dados do Search Console: ' . $response->body());
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

            // Top pages
            $this->syncDimensionData($connection, $token, $siteUrl, $start, $end, 'page');

            // Devices
            $this->syncDimensionData($connection, $token, $siteUrl, $start, $end, 'device');

            // Countries
            $this->syncDimensionData($connection, $token, $siteUrl, $start, $end, 'country');

            $connection->update([
                'sync_status' => 'success',
                'last_synced_at' => now(),
                'sync_error' => null,
            ]);

            return ['success' => true, 'synced' => $synced];
        } catch (\Throwable $e) {
            Log::error('Search Console sync error', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            $connection->update([
                'sync_status' => 'error',
                'sync_error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
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
        $response = Http::withToken($token)->post(
            "{$this->searchUrl}/sites/" . urlencode($siteUrl) . "/searchAnalytics/query",
            [
                'startDate' => $start,
                'endDate' => $end,
                'dimensions' => [$dimension],
                'rowLimit' => 25,
            ]
        );

        if (!$response->successful()) return;

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

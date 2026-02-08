<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsConnection;
use App\Models\AnalyticsDataPoint;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleAnalyticsService
{
    protected string $reportingUrl = 'https://analyticsdata.googleapis.com/v1beta';

    /**
     * Gera URL de autorização OAuth para Google Analytics
     */
    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        $clientId = Setting::get('oauth', 'google_client_id') ?: config('social_oauth.google.client_id');

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', [
                'https://www.googleapis.com/auth/analytics.readonly',
                'https://www.googleapis.com/auth/userinfo.profile',
                'https://www.googleapis.com/auth/userinfo.email',
            ]),
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

        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if (!$response->successful()) {
            Log::error('Google Analytics OAuth error', ['response' => $response->json()]);
            throw new \RuntimeException('Falha ao obter token do Google Analytics');
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
            Log::error('Google token refresh failed', ['response' => $response->json()]);
            throw new \RuntimeException('Falha ao renovar token do Google');
        }

        $data = $response->json();
        $connection->update([
            'access_token' => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return $data['access_token'];
    }

    /**
     * Busca propriedades GA4 disponíveis
     */
    public function fetchProperties(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get('https://analyticsadmin.googleapis.com/v1beta/accountSummaries');

        if (!$response->successful()) {
            Log::error('GA4 fetch properties error', ['response' => $response->json()]);
            return [];
        }

        $properties = [];
        foreach ($response->json('accountSummaries', []) as $account) {
            foreach ($account['propertySummaries'] ?? [] as $prop) {
                $propertyId = str_replace('properties/', '', $prop['property']);
                $properties[] = [
                    'id' => $propertyId,
                    'name' => $prop['displayName'] ?? 'Sem nome',
                    'account_name' => $account['displayName'] ?? '',
                    'account_id' => str_replace('accounts/', '', $account['account'] ?? ''),
                ];
            }
        }

        return $properties;
    }

    /**
     * Sincroniza dados de uma propriedade GA4
     */
    public function syncData(AnalyticsConnection $connection, ?string $startDate = null, ?string $endDate = null): array
    {
        $token = $this->getValidToken($connection);
        $propertyId = $connection->config['property_id'] ?? $connection->external_id;
        $start = $startDate ?: now()->subDays(30)->format('Y-m-d');
        $end = $endDate ?: now()->format('Y-m-d');

        $connection->update(['sync_status' => 'syncing']);

        try {
            // Buscar dados diários
            $response = Http::withToken($token)->post(
                "{$this->reportingUrl}/properties/{$propertyId}:runReport",
                [
                    'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
                    'dimensions' => [['name' => 'date']],
                    'metrics' => [
                        ['name' => 'sessions'],
                        ['name' => 'totalUsers'],
                        ['name' => 'newUsers'],
                        ['name' => 'screenPageViews'],
                        ['name' => 'bounceRate'],
                        ['name' => 'averageSessionDuration'],
                        ['name' => 'engagedSessions'],
                        ['name' => 'engagementRate'],
                        ['name' => 'eventCount'],
                    ],
                ]
            );

            if (!$response->successful()) {
                throw new \RuntimeException('Erro ao buscar relatório GA4: ' . $response->body());
            }

            $rows = $response->json('rows', []);
            $metricHeaders = $response->json('metricHeaders', []);
            $synced = 0;

            foreach ($rows as $row) {
                $dateStr = $row['dimensionValues'][0]['value'] ?? null;
                if (!$dateStr) continue;

                $date = Carbon::createFromFormat('Ymd', $dateStr)->format('Y-m-d');

                foreach ($metricHeaders as $i => $header) {
                    $metricKey = $this->mapGaMetric($header['name']);
                    $value = floatval($row['metricValues'][$i]['value'] ?? 0);

                    AnalyticsDataPoint::updateOrCreate(
                        [
                            'analytics_connection_id' => $connection->id,
                            'metric_key' => $metricKey,
                            'date' => $date,
                            'dimension_key' => null,
                            'dimension_value' => null,
                        ],
                        [
                            'brand_id' => $connection->brand_id,
                            'platform' => 'google_analytics',
                            'value' => $value,
                        ]
                    );
                    $synced++;
                }
            }

            // Buscar dados por source/medium
            $this->syncDimensionData($connection, $token, $propertyId, $start, $end, 'sessionSource', 'source');
            $this->syncDimensionData($connection, $token, $propertyId, $start, $end, 'sessionMedium', 'medium');
            $this->syncDimensionData($connection, $token, $propertyId, $start, $end, 'deviceCategory', 'device');
            $this->syncDimensionData($connection, $token, $propertyId, $start, $end, 'country', 'country');

            // Buscar páginas mais visitadas
            $this->syncTopPages($connection, $token, $propertyId, $start, $end);

            $connection->update([
                'sync_status' => 'success',
                'last_synced_at' => now(),
                'sync_error' => null,
            ]);

            return ['success' => true, 'synced' => $synced];
        } catch (\Throwable $e) {
            Log::error('GA4 sync error', [
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
     * Busca dados por dimensão (source, medium, device, country)
     */
    protected function syncDimensionData(
        AnalyticsConnection $connection,
        string $token,
        string $propertyId,
        string $start,
        string $end,
        string $gaDimension,
        string $dimensionKey
    ): void {
        $response = Http::withToken($token)->post(
            "{$this->reportingUrl}/properties/{$propertyId}:runReport",
            [
                'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
                'dimensions' => [['name' => $gaDimension]],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                ],
                'limit' => 20,
                'orderBys' => [
                    ['metric' => ['metricName' => 'sessions'], 'desc' => true],
                ],
            ]
        );

        if (!$response->successful()) return;

        foreach ($response->json('rows', []) as $row) {
            $dimValue = $row['dimensionValues'][0]['value'] ?? '(not set)';

            AnalyticsDataPoint::updateOrCreate(
                [
                    'analytics_connection_id' => $connection->id,
                    'metric_key' => 'sessions',
                    'date' => $end,
                    'dimension_key' => $dimensionKey,
                    'dimension_value' => $dimValue,
                ],
                [
                    'brand_id' => $connection->brand_id,
                    'platform' => 'google_analytics',
                    'value' => floatval($row['metricValues'][0]['value'] ?? 0),
                    'extra' => [
                        'users' => floatval($row['metricValues'][1]['value'] ?? 0),
                    ],
                ]
            );
        }
    }

    /**
     * Busca top pages
     */
    protected function syncTopPages(
        AnalyticsConnection $connection,
        string $token,
        string $propertyId,
        string $start,
        string $end
    ): void {
        $response = Http::withToken($token)->post(
            "{$this->reportingUrl}/properties/{$propertyId}:runReport",
            [
                'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
                'dimensions' => [['name' => 'pagePath']],
                'metrics' => [
                    ['name' => 'screenPageViews'],
                    ['name' => 'totalUsers'],
                    ['name' => 'averageSessionDuration'],
                ],
                'limit' => 20,
                'orderBys' => [
                    ['metric' => ['metricName' => 'screenPageViews'], 'desc' => true],
                ],
            ]
        );

        if (!$response->successful()) return;

        foreach ($response->json('rows', []) as $row) {
            $page = $row['dimensionValues'][0]['value'] ?? '/';

            AnalyticsDataPoint::updateOrCreate(
                [
                    'analytics_connection_id' => $connection->id,
                    'metric_key' => 'pageviews',
                    'date' => $end,
                    'dimension_key' => 'page',
                    'dimension_value' => $page,
                ],
                [
                    'brand_id' => $connection->brand_id,
                    'platform' => 'google_analytics',
                    'value' => floatval($row['metricValues'][0]['value'] ?? 0),
                    'extra' => [
                        'users' => floatval($row['metricValues'][1]['value'] ?? 0),
                        'avg_duration' => floatval($row['metricValues'][2]['value'] ?? 0),
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

    protected function mapGaMetric(string $gaName): string
    {
        return match ($gaName) {
            'sessions' => 'sessions',
            'totalUsers' => 'users',
            'newUsers' => 'new_users',
            'screenPageViews' => 'pageviews',
            'bounceRate' => 'bounce_rate',
            'averageSessionDuration' => 'avg_session_duration',
            'engagedSessions' => 'engaged_sessions',
            'engagementRate' => 'engagement_rate',
            'eventCount' => 'event_count',
            default => $gaName,
        };
    }
}

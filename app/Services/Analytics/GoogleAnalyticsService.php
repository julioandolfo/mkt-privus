<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsConnection;
use App\Models\AnalyticsDataPoint;
use App\Models\Setting;
use App\Models\SystemLog;
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

        if (empty($clientId)) {
            SystemLog::error('analytics', 'ga.auth.no_client_id', 'Google Client ID nao configurado');
            throw new \RuntimeException('Google Client ID não configurado. Verifique as configurações OAuth.');
        }

        $scopes = [
            'https://www.googleapis.com/auth/analytics.readonly',
            'https://www.googleapis.com/auth/userinfo.profile',
            'https://www.googleapis.com/auth/userinfo.email',
        ];

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        SystemLog::debug('analytics', 'ga.auth.url', 'URL de autorizacao GA4 gerada', [
            'client_id_preview' => substr($clientId, 0, 15) . '...',
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
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

        SystemLog::info('analytics', 'ga.exchange.start', 'Trocando code por token GA4', [
            'redirect_uri' => $redirectUri,
            'has_client_id' => !empty($clientId),
            'has_client_secret' => !empty($clientSecret),
            'code_preview' => substr($code, 0, 15) . '...',
        ]);

        if (empty($clientId) || empty($clientSecret)) {
            SystemLog::error('analytics', 'ga.exchange.missing_creds', 'Client ID ou Secret nao configurados', [
                'has_client_id' => !empty($clientId),
                'has_client_secret' => !empty($clientSecret),
                'source_db_client_id' => !empty(Setting::get('oauth', 'google_client_id')),
                'source_config_client_id' => !empty(config('social_oauth.google.client_id')),
            ]);
            throw new \RuntimeException('Client ID ou Client Secret não configurados');
        }

        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if (!$response->successful()) {
            $body = $response->json();
            SystemLog::error('analytics', 'ga.exchange.failed', 'Falha ao trocar code por token GA4', [
                'status' => $response->status(),
                'error' => $body['error'] ?? 'unknown',
                'error_description' => $body['error_description'] ?? 'N/A',
                'redirect_uri' => $redirectUri,
                'body' => $body,
            ]);
            Log::error('Google Analytics OAuth error', ['response' => $body]);
            throw new \RuntimeException('Falha ao obter token do Google Analytics: ' . ($body['error_description'] ?? $body['error'] ?? 'HTTP ' . $response->status()));
        }

        $data = $response->json();
        SystemLog::info('analytics', 'ga.exchange.success', 'Token GA4 recebido com sucesso', [
            'has_access_token' => !empty($data['access_token']),
            'has_refresh_token' => !empty($data['refresh_token']),
            'expires_in' => $data['expires_in'] ?? 'N/A',
            'token_type' => $data['token_type'] ?? 'N/A',
            'scope' => $data['scope'] ?? 'N/A',
        ]);

        return $data;
    }

    /**
     * Refresh do access token
     */
    public function refreshToken(AnalyticsConnection $connection): string
    {
        $clientId = Setting::get('oauth', 'google_client_id') ?: config('social_oauth.google.client_id');
        $clientSecret = Setting::get('oauth', 'google_client_secret') ?: config('social_oauth.google.client_secret');

        SystemLog::debug('analytics', 'ga.refresh.start', "Renovando token GA4 para conexao #{$connection->id}");

        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->successful()) {
            $body = $response->json();
            SystemLog::error('analytics', 'ga.refresh.failed', "Falha ao renovar token GA4 para conexao #{$connection->id}", [
                'status' => $response->status(),
                'error' => $body['error'] ?? 'unknown',
                'error_description' => $body['error_description'] ?? 'N/A',
            ]);
            Log::error('Google token refresh failed', ['response' => $body]);
            throw new \RuntimeException('Falha ao renovar token do Google: ' . ($body['error_description'] ?? $body['error'] ?? 'unknown'));
        }

        $data = $response->json();
        $connection->update([
            'access_token' => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        SystemLog::info('analytics', 'ga.refresh.success', "Token GA4 renovado para conexao #{$connection->id}");

        return $data['access_token'];
    }

    /**
     * Busca propriedades GA4 disponíveis
     */
    public function fetchProperties(string $accessToken): array
    {
        SystemLog::info('analytics', 'ga.properties.start', 'Buscando propriedades GA4...');

        $response = Http::withToken($accessToken)
            ->get('https://analyticsadmin.googleapis.com/v1beta/accountSummaries');

        if (!$response->successful()) {
            $body = $response->json();
            SystemLog::error('analytics', 'ga.properties.failed', 'Falha ao buscar propriedades GA4', [
                'status' => $response->status(),
                'error' => $body['error']['message'] ?? 'unknown',
                'code' => $body['error']['code'] ?? 'N/A',
                'body' => $body,
            ]);
            Log::error('GA4 fetch properties error', ['response' => $body]);
            throw new \RuntimeException('Falha ao buscar propriedades GA4: ' . ($body['error']['message'] ?? 'HTTP ' . $response->status()));
        }

        $properties = [];
        $accountSummaries = $response->json('accountSummaries', []);

        SystemLog::debug('analytics', 'ga.properties.raw', 'Resposta da API accountSummaries', [
            'accounts_count' => count($accountSummaries),
            'raw_keys' => array_keys($response->json()),
        ]);

        foreach ($accountSummaries as $account) {
            $propertySummaries = $account['propertySummaries'] ?? [];
            SystemLog::debug('analytics', 'ga.properties.account', "Conta: " . ($account['displayName'] ?? 'N/A'), [
                'account_id' => $account['account'] ?? 'N/A',
                'account_name' => $account['displayName'] ?? 'N/A',
                'properties_count' => count($propertySummaries),
            ]);

            foreach ($propertySummaries as $prop) {
                $propertyId = str_replace('properties/', '', $prop['property']);
                $properties[] = [
                    'id' => $propertyId,
                    'name' => $prop['displayName'] ?? 'Sem nome',
                    'account_name' => $account['displayName'] ?? '',
                    'account_id' => str_replace('accounts/', '', $account['account'] ?? ''),
                ];
            }
        }

        SystemLog::info('analytics', 'ga.properties.complete', count($properties) . " propriedade(s) GA4 encontrada(s)", [
            'count' => count($properties),
            'properties' => array_map(fn($p) => "{$p['id']}: {$p['name']}", $properties),
        ]);

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

        SystemLog::info('analytics', 'ga.sync.start', "Sincronizando GA4 #{$connection->id} ({$propertyId})", [
            'connection_id' => $connection->id,
            'property_id' => $propertyId,
            'start_date' => $start,
            'end_date' => $end,
        ]);

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

            SystemLog::info('analytics', 'ga.sync.complete', "GA4 sincronizado: {$synced} pontos", [
                'connection_id' => $connection->id,
                'synced' => $synced,
            ]);

            return ['success' => true, 'synced' => $synced];
        } catch (\Throwable $e) {
            SystemLog::error('analytics', 'ga.sync.error', "Erro ao sincronizar GA4: {$e->getMessage()}", [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
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

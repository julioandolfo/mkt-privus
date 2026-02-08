<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsConnection;
use App\Models\AnalyticsDataPoint;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleAdsService
{
    protected string $baseUrl = 'https://googleads.googleapis.com/v16';

    /**
     * Gera URL de autorização OAuth para Google Ads
     */
    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        $clientId = Setting::get('oauth', 'google_client_id') ?: config('social_oauth.google.client_id');

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/adwords https://www.googleapis.com/auth/userinfo.profile',
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
            throw new \RuntimeException('Falha ao obter token do Google Ads');
        }

        return $response->json();
    }

    /**
     * Refresh token
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
            throw new \RuntimeException('Falha ao renovar token do Google Ads');
        }

        $data = $response->json();
        $connection->update([
            'access_token' => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return $data['access_token'];
    }

    /**
     * Busca contas acessíveis via Google Ads API (REST / listAccessibleCustomers)
     */
    public function fetchCustomers(string $accessToken): array
    {
        $developerToken = Setting::get('google', 'ads_developer_token') ?: config('services.google_ads.developer_token', '');

        $response = Http::withToken($accessToken)
            ->withHeaders(['developer-token' => $developerToken])
            ->get("{$this->baseUrl}/customers:listAccessibleCustomers");

        if (!$response->successful()) {
            Log::error('Google Ads fetch customers error', ['response' => $response->json()]);
            return [];
        }

        $customers = [];
        foreach ($response->json('resourceNames', []) as $resource) {
            $customerId = str_replace('customers/', '', $resource);

            // Buscar detalhes do customer
            $detail = Http::withToken($accessToken)
                ->withHeaders([
                    'developer-token' => $developerToken,
                    'login-customer-id' => $customerId,
                ])
                ->post("{$this->baseUrl}/customers/{$customerId}/googleAds:searchStream", [
                    'query' => "SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone FROM customer LIMIT 1",
                ]);

            $name = 'Conta ' . $customerId;
            $currency = 'BRL';
            if ($detail->successful()) {
                $results = $detail->json();
                $customerData = $results[0]['results'][0]['customer'] ?? [];
                $name = $customerData['descriptiveName'] ?? $name;
                $currency = $customerData['currencyCode'] ?? $currency;
            }

            $customers[] = [
                'id' => $customerId,
                'name' => $name,
                'currency' => $currency,
            ];
        }

        return $customers;
    }

    /**
     * Sincroniza dados de uma conta Google Ads
     */
    public function syncData(AnalyticsConnection $connection, ?string $startDate = null, ?string $endDate = null): array
    {
        $token = $this->getValidToken($connection);
        $customerId = $connection->external_id;
        $developerToken = Setting::get('google', 'ads_developer_token') ?: config('services.google_ads.developer_token', '');
        $start = $startDate ?: now()->subDays(30)->format('Y-m-d');
        $end = $endDate ?: now()->format('Y-m-d');

        $connection->update(['sync_status' => 'syncing']);

        try {
            // Métricas diárias da conta
            $query = "SELECT segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, "
                . "metrics.ctr, metrics.average_cpc, metrics.conversions, metrics.conversions_value, "
                . "metrics.cost_per_conversion "
                . "FROM customer "
                . "WHERE segments.date BETWEEN '{$start}' AND '{$end}' "
                . "ORDER BY segments.date";

            $response = Http::withToken($token)
                ->withHeaders([
                    'developer-token' => $developerToken,
                    'login-customer-id' => $customerId,
                ])
                ->post("{$this->baseUrl}/customers/{$customerId}/googleAds:searchStream", [
                    'query' => $query,
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException('Erro ao buscar dados do Google Ads: ' . $response->body());
            }

            $synced = 0;
            foreach ($response->json() as $batch) {
                foreach ($batch['results'] ?? [] as $row) {
                    $date = $row['segments']['date'] ?? null;
                    if (!$date) continue;

                    $m = $row['metrics'] ?? [];
                    $metrics = [
                        'spend' => ($m['costMicros'] ?? 0) / 1000000,
                        'impressions' => floatval($m['impressions'] ?? 0),
                        'clicks' => floatval($m['clicks'] ?? 0),
                        'ctr' => floatval($m['ctr'] ?? 0),
                        'cpc' => ($m['averageCpc'] ?? 0) / 1000000,
                        'conversions' => floatval($m['conversions'] ?? 0),
                        'conversion_value' => floatval($m['conversionsValue'] ?? 0),
                        'cost_per_conversion' => ($m['costPerConversion'] ?? 0) / 1000000,
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
                                'platform' => 'google_ads',
                                'value' => $value,
                            ]
                        );
                        $synced++;
                    }
                }
            }

            // Dados por campanha
            $this->syncCampaignData($connection, $token, $customerId, $developerToken, $start, $end);

            $connection->update([
                'sync_status' => 'success',
                'last_synced_at' => now(),
                'sync_error' => null,
            ]);

            return ['success' => true, 'synced' => $synced];
        } catch (\Throwable $e) {
            Log::error('Google Ads sync error', [
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

    protected function syncCampaignData(
        AnalyticsConnection $connection,
        string $token,
        string $customerId,
        string $developerToken,
        string $start,
        string $end
    ): void {
        $query = "SELECT campaign.id, campaign.name, campaign.status, "
            . "metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.ctr, metrics.conversions "
            . "FROM campaign "
            . "WHERE segments.date BETWEEN '{$start}' AND '{$end}' "
            . "AND campaign.status != 'REMOVED' "
            . "ORDER BY metrics.cost_micros DESC "
            . "LIMIT 25";

        $response = Http::withToken($token)
            ->withHeaders([
                'developer-token' => $developerToken,
                'login-customer-id' => $customerId,
            ])
            ->post("{$this->baseUrl}/customers/{$customerId}/googleAds:searchStream", [
                'query' => $query,
            ]);

        if (!$response->successful()) return;

        foreach ($response->json() as $batch) {
            foreach ($batch['results'] ?? [] as $row) {
                $campaign = $row['campaign'] ?? [];
                $m = $row['metrics'] ?? [];
                $campaignName = $campaign['name'] ?? 'Campanha';

                $campaignMetrics = [
                    'spend' => ($m['costMicros'] ?? 0) / 1000000,
                    'impressions' => floatval($m['impressions'] ?? 0),
                    'clicks' => floatval($m['clicks'] ?? 0),
                    'ctr' => floatval($m['ctr'] ?? 0),
                    'conversions' => floatval($m['conversions'] ?? 0),
                ];

                foreach ($campaignMetrics as $key => $value) {
                    AnalyticsDataPoint::updateOrCreate(
                        [
                            'analytics_connection_id' => $connection->id,
                            'metric_key' => $key,
                            'date' => $end,
                            'dimension_key' => 'campaign',
                            'dimension_value' => $campaignName,
                        ],
                        [
                            'brand_id' => $connection->brand_id,
                            'platform' => 'google_ads',
                            'value' => $value,
                            'extra' => [
                                'campaign_id' => $campaign['id'] ?? null,
                                'status' => $campaign['status'] ?? null,
                            ],
                        ]
                    );
                }
            }
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

<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsConnection;
use App\Models\AnalyticsDataPoint;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaAdsService
{
    protected string $baseUrl = 'https://graph.facebook.com';
    protected string $apiVersion = 'v21.0';

    /**
     * Gera URL de autorização OAuth para Meta Ads
     */
    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        $appId = Setting::get('oauth', 'meta_app_id') ?: config('social_oauth.meta.app_id');

        $scopes = implode(',', [
            'ads_read',
            'ads_management',
            'business_management',
            'read_insights',
        ]);

        $params = http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'scope' => $scopes,
            'response_type' => 'code',
            'state' => $state,
        ]);

        return "https://www.facebook.com/{$this->apiVersion}/dialog/oauth?{$params}";
    }

    /**
     * Troca code por access token
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $appId = Setting::get('oauth', 'meta_app_id') ?: config('social_oauth.meta.app_id');
        $appSecret = Setting::get('oauth', 'meta_app_secret') ?: config('social_oauth.meta.app_secret');

        $response = Http::get("{$this->baseUrl}/{$this->apiVersion}/oauth/access_token", [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Falha ao obter token da Meta: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Busca contas de anúncio disponíveis
     */
    public function fetchAdAccounts(string $accessToken): array
    {
        $response = Http::get("{$this->baseUrl}/{$this->apiVersion}/me/adaccounts", [
            'access_token' => $accessToken,
            'fields' => 'id,name,account_id,account_status,currency,timezone_name,business_name',
        ]);

        if (!$response->successful()) {
            Log::error('Meta Ads fetch accounts error', ['response' => $response->json()]);
            return [];
        }

        $accounts = [];
        foreach ($response->json('data', []) as $account) {
            $accounts[] = [
                'id' => $account['account_id'] ?? $account['id'],
                'name' => $account['name'] ?? 'Conta ' . ($account['account_id'] ?? ''),
                'business_name' => $account['business_name'] ?? null,
                'currency' => $account['currency'] ?? 'BRL',
                'timezone' => $account['timezone_name'] ?? null,
                'status' => $account['account_status'] ?? null,
            ];
        }

        return $accounts;
    }

    /**
     * Sincroniza dados de uma conta de anúncio Meta
     */
    public function syncData(AnalyticsConnection $connection, ?string $startDate = null, ?string $endDate = null): array
    {
        $token = $connection->access_token;
        $accountId = $connection->external_id;
        $start = $startDate ?: now()->subDays(30)->format('Y-m-d');
        $end = $endDate ?: now()->format('Y-m-d');

        $connection->update(['sync_status' => 'syncing']);

        try {
            // Insights diários da conta
            $response = Http::get("{$this->baseUrl}/{$this->apiVersion}/act_{$accountId}/insights", [
                'access_token' => $token,
                'time_range' => json_encode(['since' => $start, 'until' => $end]),
                'time_increment' => 1, // Diário
                'fields' => implode(',', [
                    'spend', 'impressions', 'clicks', 'ctr', 'cpc', 'cpm',
                    'actions', 'action_values', 'reach', 'frequency',
                    'conversions', 'cost_per_action_type',
                ]),
                'limit' => 100,
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException('Erro ao buscar insights Meta Ads: ' . $response->body());
            }

            $synced = 0;
            $data = $response->json('data', []);

            foreach ($data as $dayData) {
                $date = $dayData['date_start'] ?? null;
                if (!$date) continue;

                $metrics = [
                    'spend' => floatval($dayData['spend'] ?? 0),
                    'impressions' => floatval($dayData['impressions'] ?? 0),
                    'clicks' => floatval($dayData['clicks'] ?? 0),
                    'ctr' => floatval($dayData['ctr'] ?? 0),
                    'cpc' => floatval($dayData['cpc'] ?? 0),
                    'cpm' => floatval($dayData['cpm'] ?? 0),
                    'reach' => floatval($dayData['reach'] ?? 0),
                    'frequency' => floatval($dayData['frequency'] ?? 0),
                ];

                // Extrair conversões
                $conversions = 0;
                $revenue = 0;
                foreach ($dayData['actions'] ?? [] as $action) {
                    if (in_array($action['action_type'], ['offsite_conversion', 'purchase', 'lead', 'complete_registration'])) {
                        $conversions += intval($action['value'] ?? 0);
                    }
                }
                foreach ($dayData['action_values'] ?? [] as $actionValue) {
                    if ($actionValue['action_type'] === 'purchase') {
                        $revenue += floatval($actionValue['value'] ?? 0);
                    }
                }

                $metrics['conversions'] = $conversions;
                $metrics['revenue'] = $revenue;

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
                            'platform' => 'meta_ads',
                            'value' => $value,
                        ]
                    );
                    $synced++;
                }
            }

            // Dados por campanha
            $this->syncCampaignData($connection, $token, $accountId, $start, $end);

            $connection->update([
                'sync_status' => 'success',
                'last_synced_at' => now(),
                'sync_error' => null,
            ]);

            return ['success' => true, 'synced' => $synced];
        } catch (\Throwable $e) {
            Log::error('Meta Ads sync error', [
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
     * Sincroniza dados por campanha
     */
    protected function syncCampaignData(
        AnalyticsConnection $connection,
        string $token,
        string $accountId,
        string $start,
        string $end
    ): void {
        $response = Http::get("{$this->baseUrl}/{$this->apiVersion}/act_{$accountId}/campaigns", [
            'access_token' => $token,
            'fields' => 'id,name,status,objective,insights.time_range({"since":"' . $start . '","until":"' . $end . '"}){spend,impressions,clicks,ctr,cpc,actions,action_values}',
            'limit' => 50,
        ]);

        if (!$response->successful()) return;

        foreach ($response->json('data', []) as $campaign) {
            $campaignName = $campaign['name'] ?? 'Campanha';
            $insights = $campaign['insights']['data'][0] ?? [];

            if (empty($insights)) continue;

            $campaignMetrics = [
                'spend' => floatval($insights['spend'] ?? 0),
                'impressions' => floatval($insights['impressions'] ?? 0),
                'clicks' => floatval($insights['clicks'] ?? 0),
                'ctr' => floatval($insights['ctr'] ?? 0),
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
                        'platform' => 'meta_ads',
                        'value' => $value,
                        'extra' => ['campaign_id' => $campaign['id'], 'status' => $campaign['status'] ?? null],
                    ]
                );
            }
        }
    }
}

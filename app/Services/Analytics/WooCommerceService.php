<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsConnection;
use App\Models\AnalyticsDataPoint;
use App\Models\SystemLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class WooCommerceService
{
    /**
     * Testa conexao com a loja WooCommerce
     */
    public function testConnection(string $storeUrl, string $consumerKey, string $consumerSecret): array
    {
        $url = rtrim($storeUrl, '/') . '/wp-json/wc/v3/system_status';

        try {
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->timeout(15)
                ->get($url);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'store_name' => $data['environment']['site_url'] ?? $storeUrl,
                    'wc_version' => $data['environment']['version'] ?? 'desconhecida',
                    'currency' => $data['settings']['currency'] ?? 'BRL',
                ];
            }

            if ($response->status() === 401) {
                return ['success' => false, 'error' => 'Credenciais inválidas. Verifique Consumer Key e Secret.'];
            }

            return ['success' => false, 'error' => 'Erro HTTP ' . $response->status() . ': ' . $response->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Não foi possível conectar: ' . $e->getMessage()];
        }
    }

    /**
     * Sincroniza dados de pedidos WooCommerce
     */
    public function syncData(AnalyticsConnection $connection, ?string $startDate = null, ?string $endDate = null): array
    {
        $config = $connection->config;
        $storeUrl = $config['store_url'] ?? '';
        $consumerKey = $config['consumer_key'] ?? '';
        $consumerSecret = $config['consumer_secret'] ?? '';

        if (empty($storeUrl) || empty($consumerKey) || empty($consumerSecret)) {
            return ['success' => false, 'error' => 'Configuração WooCommerce incompleta'];
        }

        $start = $startDate ?: now()->subDays(30)->format('Y-m-d');
        $end = $endDate ?: now()->format('Y-m-d');

        $connection->update(['sync_status' => 'syncing']);

        SystemLog::info('analytics', 'woocommerce.sync.start', "Sincronizando WooCommerce: {$connection->name}", [
            'connection_id' => $connection->id,
            'store_url' => $storeUrl,
            'start' => $start,
            'end' => $end,
        ]);

        try {
            // Buscar todos os pedidos do periodo (com paginacao)
            $allOrders = $this->fetchAllOrders($storeUrl, $consumerKey, $consumerSecret, $start, $end);

            SystemLog::info('analytics', 'woocommerce.sync.orders', "Encontrados " . count($allOrders) . " pedidos", [
                'connection_id' => $connection->id,
                'total_orders' => count($allOrders),
            ]);

            // Agregar por dia
            $dailyData = $this->aggregateByDay($allOrders);

            // Salvar data points
            $synced = 0;
            foreach ($dailyData as $dateStr => $data) {
                $metrics = [
                    'wc_orders' => $data['orders'],
                    'wc_revenue' => $data['revenue'],
                    'wc_avg_order_value' => $data['orders'] > 0 ? $data['revenue'] / $data['orders'] : 0,
                    'wc_items_sold' => $data['items_sold'],
                    'wc_refunds' => $data['refunds'],
                    'wc_shipping' => $data['shipping'],
                    'wc_tax' => $data['tax'],
                    'wc_new_customers' => $data['new_customers'],
                    'wc_coupons_used' => $data['coupons_used'],
                ];

                foreach ($metrics as $key => $value) {
                    AnalyticsDataPoint::updateOrCreate(
                        [
                            'analytics_connection_id' => $connection->id,
                            'metric_key' => $key,
                            'date' => $dateStr,
                            'dimension_key' => null,
                            'dimension_value' => null,
                        ],
                        [
                            'brand_id' => $connection->brand_id,
                            'platform' => 'woocommerce',
                            'value' => $value,
                        ]
                    );
                    $synced++;
                }

                // Top produtos do dia
                foreach (array_slice($data['products'], 0, 10) as $product) {
                    AnalyticsDataPoint::updateOrCreate(
                        [
                            'analytics_connection_id' => $connection->id,
                            'metric_key' => 'wc_product_revenue',
                            'date' => $dateStr,
                            'dimension_key' => 'product',
                            'dimension_value' => $product['name'],
                        ],
                        [
                            'brand_id' => $connection->brand_id,
                            'platform' => 'woocommerce',
                            'value' => $product['revenue'],
                            'extra' => [
                                'quantity' => $product['quantity'],
                                'product_id' => $product['product_id'],
                            ],
                        ]
                    );
                    $synced++;
                }

                // Metodos de pagamento
                foreach ($data['payment_methods'] as $method => $count) {
                    AnalyticsDataPoint::updateOrCreate(
                        [
                            'analytics_connection_id' => $connection->id,
                            'metric_key' => 'wc_payment_method',
                            'date' => $dateStr,
                            'dimension_key' => 'payment_method',
                            'dimension_value' => $method,
                        ],
                        [
                            'brand_id' => $connection->brand_id,
                            'platform' => 'woocommerce',
                            'value' => $count,
                        ]
                    );
                    $synced++;
                }
            }

            $connection->update([
                'sync_status' => 'success',
                'last_synced_at' => now(),
                'sync_error' => null,
            ]);

            SystemLog::info('analytics', 'woocommerce.sync.complete', "WooCommerce sync concluido: {$synced} pontos", [
                'connection_id' => $connection->id,
                'synced' => $synced,
            ]);

            return ['success' => true, 'synced' => $synced];
        } catch (\Throwable $e) {
            SystemLog::error('analytics', 'woocommerce.sync.error', "Erro ao sincronizar WooCommerce: {$e->getMessage()}", [
                'connection_id' => $connection->id,
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
     * Busca todos os pedidos com paginacao
     */
    protected function fetchAllOrders(string $storeUrl, string $key, string $secret, string $start, string $end): array
    {
        $allOrders = [];
        $page = 1;
        $perPage = 100;
        $baseUrl = rtrim($storeUrl, '/') . '/wp-json/wc/v3/orders';

        // Status que contam como vendas efetivas
        $statuses = 'completed,processing,on-hold';

        do {
            $response = Http::withBasicAuth($key, $secret)
                ->timeout(30)
                ->get($baseUrl, [
                    'after' => Carbon::parse($start)->startOfDay()->toIso8601String(),
                    'before' => Carbon::parse($end)->endOfDay()->toIso8601String(),
                    'status' => $statuses,
                    'per_page' => $perPage,
                    'page' => $page,
                    'orderby' => 'date',
                    'order' => 'asc',
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException("Erro ao buscar pedidos WooCommerce (page {$page}): HTTP {$response->status()}");
            }

            $orders = $response->json();
            if (empty($orders)) break;

            $allOrders = array_merge($allOrders, $orders);
            $totalPages = (int) ($response->header('X-WP-TotalPages') ?? 1);
            $page++;
        } while ($page <= $totalPages);

        // Buscar reembolsos separadamente
        $refundOrders = $this->fetchRefunds($storeUrl, $key, $secret, $start, $end);
        foreach ($refundOrders as $refund) {
            $allOrders[] = array_merge($refund, ['_is_refund' => true]);
        }

        return $allOrders;
    }

    /**
     * Busca pedidos reembolsados
     */
    protected function fetchRefunds(string $storeUrl, string $key, string $secret, string $start, string $end): array
    {
        $baseUrl = rtrim($storeUrl, '/') . '/wp-json/wc/v3/orders';

        try {
            $response = Http::withBasicAuth($key, $secret)
                ->timeout(30)
                ->get($baseUrl, [
                    'after' => Carbon::parse($start)->startOfDay()->toIso8601String(),
                    'before' => Carbon::parse($end)->endOfDay()->toIso8601String(),
                    'status' => 'refunded',
                    'per_page' => 100,
                ]);

            return $response->successful() ? $response->json() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Agrega pedidos por dia
     */
    protected function aggregateByDay(array $orders): array
    {
        $daily = [];

        foreach ($orders as $order) {
            $dateCreated = $order['date_created'] ?? $order['date_completed'] ?? null;
            if (!$dateCreated) continue;

            $dateStr = Carbon::parse($dateCreated)->format('Y-m-d');

            if (!isset($daily[$dateStr])) {
                $daily[$dateStr] = [
                    'orders' => 0,
                    'revenue' => 0,
                    'items_sold' => 0,
                    'refunds' => 0,
                    'shipping' => 0,
                    'tax' => 0,
                    'new_customers' => 0,
                    'coupons_used' => 0,
                    'products' => [],
                    'payment_methods' => [],
                ];
            }

            $isRefund = !empty($order['_is_refund']);

            if ($isRefund) {
                $daily[$dateStr]['refunds'] += abs(floatval($order['total'] ?? 0));
                continue;
            }

            $total = floatval($order['total'] ?? 0);
            $shipping = floatval($order['shipping_total'] ?? 0);
            $tax = floatval($order['total_tax'] ?? 0);

            $daily[$dateStr]['orders']++;
            $daily[$dateStr]['revenue'] += $total;
            $daily[$dateStr]['shipping'] += $shipping;
            $daily[$dateStr]['tax'] += $tax;

            // Itens vendidos
            $lineItems = $order['line_items'] ?? [];
            foreach ($lineItems as $item) {
                $qty = intval($item['quantity'] ?? 1);
                $daily[$dateStr]['items_sold'] += $qty;

                // Agregar produtos
                $productName = $item['name'] ?? 'Produto';
                $productId = $item['product_id'] ?? 0;
                $itemTotal = floatval($item['total'] ?? 0);

                $found = false;
                foreach ($daily[$dateStr]['products'] as &$p) {
                    if ($p['product_id'] === $productId) {
                        $p['quantity'] += $qty;
                        $p['revenue'] += $itemTotal;
                        $found = true;
                        break;
                    }
                }
                unset($p);

                if (!$found) {
                    $daily[$dateStr]['products'][] = [
                        'product_id' => $productId,
                        'name' => $productName,
                        'quantity' => $qty,
                        'revenue' => $itemTotal,
                    ];
                }
            }

            // Novo cliente
            $customerId = $order['customer_id'] ?? 0;
            if ($customerId === 0 || ($order['customer_note'] ?? '') === '') {
                // Heuristica: customer_id = 0 pode ser guest checkout
                // Para melhor precisao, checar se e o primeiro pedido do email
                $daily[$dateStr]['new_customers']++;
            }

            // Cupons
            $coupons = $order['coupon_lines'] ?? [];
            $daily[$dateStr]['coupons_used'] += count($coupons);

            // Metodo de pagamento
            $paymentMethod = $order['payment_method_title'] ?? $order['payment_method'] ?? 'Desconhecido';
            if (!isset($daily[$dateStr]['payment_methods'][$paymentMethod])) {
                $daily[$dateStr]['payment_methods'][$paymentMethod] = 0;
            }
            $daily[$dateStr]['payment_methods'][$paymentMethod]++;
        }

        // Ordenar produtos por receita (desc)
        foreach ($daily as &$dayData) {
            usort($dayData['products'], fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        }
        unset($dayData);

        return $daily;
    }
}

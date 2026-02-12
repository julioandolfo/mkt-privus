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
     * Status padrão que contam como vendas efetivas
     */
    public const DEFAULT_ORDER_STATUSES = ['completed', 'processing', 'on-hold'];

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

        // Status de pedidos que contam como receita (padrão + personalizados)
        $orderStatuses = $this->resolveOrderStatuses($config);

        $start = $startDate ?: now()->subDays(30)->format('Y-m-d');
        $end = $endDate ?: now()->format('Y-m-d');

        $connection->update(['sync_status' => 'syncing']);

        SystemLog::info('analytics', 'woocommerce.sync.start', "Sincronizando WooCommerce: {$connection->name}", [
            'connection_id' => $connection->id,
            'store_url' => $storeUrl,
            'start' => $start,
            'end' => $end,
            'order_statuses' => $orderStatuses,
        ]);

        try {
            // Buscar todos os pedidos do periodo (com paginacao)
            $allOrders = $this->fetchAllOrders($storeUrl, $consumerKey, $consumerSecret, $start, $end, $orderStatuses);

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
    protected function fetchAllOrders(string $storeUrl, string $key, string $secret, string $start, string $end, array $orderStatuses = []): array
    {
        $allOrders = [];
        $page = 1;
        $perPage = 100;
        $baseUrl = rtrim($storeUrl, '/') . '/wp-json/wc/v3/orders';

        // Status que contam como vendas efetivas (padrão + personalizados)
        $statuses = !empty($orderStatuses) ? implode(',', $orderStatuses) : 'completed,processing,on-hold';

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
     * Resolve os status de pedido a utilizar na sincronização.
     * Combina os status padrão com os personalizados configurados na conexão.
     */
    protected function resolveOrderStatuses(array $config): array
    {
        $customStatuses = $config['order_statuses'] ?? [];

        if (empty($customStatuses)) {
            return self::DEFAULT_ORDER_STATUSES;
        }

        // Retornar a lista personalizada (sem duplicatas)
        return array_values(array_unique($customStatuses));
    }

    /**
     * Busca os status de pedido disponíveis na loja WooCommerce.
     * Inclui status padrão do WC e status personalizados de plugins.
     */
    public function fetchOrderStatuses(string $storeUrl, string $consumerKey, string $consumerSecret): array
    {
        $baseUrl = rtrim($storeUrl, '/');

        SystemLog::info('analytics', 'woocommerce.statuses.fetch_start', "Buscando status de pedido de: {$baseUrl}", [
            'store_url' => $baseUrl,
            'has_key' => !empty($consumerKey),
        ]);

        // Método 1: reports/orders/totals — lista todos os status com contagem de pedidos
        // Este é o endpoint mais confiável para obter status (incluindo personalizados)
        $reportsUrl = $baseUrl . '/wp-json/wc/v3/reports/orders/totals';

        try {
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->timeout(15)
                ->get($reportsUrl);

            SystemLog::debug('analytics', 'woocommerce.statuses.reports_response', "reports/orders/totals: HTTP {$response->status()}", [
                'url' => $reportsUrl,
                'status' => $response->status(),
                'body_preview' => substr($response->body(), 0, 500),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (is_array($data) && !empty($data)) {
                    $statuses = collect($data)->map(fn($item) => [
                        'slug' => $item['slug'] ?? '',
                        'name' => $item['name'] ?? $item['slug'] ?? '',
                        'total' => intval($item['total'] ?? 0),
                    ])->filter(fn($item) => !empty($item['slug']))->values()->toArray();

                    if (!empty($statuses)) {
                        SystemLog::info('analytics', 'woocommerce.statuses.fetched', count($statuses) . " status encontrados via reports/orders/totals", [
                            'statuses' => array_map(fn($s) => $s['slug'] . ' (' . $s['total'] . ')', $statuses),
                        ]);
                        return $statuses;
                    }
                }
            }
        } catch (\Throwable $e) {
            SystemLog::warning('analytics', 'woocommerce.statuses.reports_error', "Erro ao buscar reports/orders/totals: {$e->getMessage()}", [
                'url' => $reportsUrl,
                'error' => $e->getMessage(),
            ]);
        }

        // Método 2: Buscar um pedido recente e extrair status disponíveis do schema
        // via OPTIONS ou buscar pedidos com status diferentes
        $ordersUrl = $baseUrl . '/wp-json/wc/v3/orders';

        try {
            // Buscar pedidos recentes para ver quais status existem
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->timeout(15)
                ->get($ordersUrl, [
                    'per_page' => 100,
                    'orderby' => 'date',
                    'order' => 'desc',
                ]);

            SystemLog::debug('analytics', 'woocommerce.statuses.orders_response', "orders scan: HTTP {$response->status()}", [
                'url' => $ordersUrl,
                'status' => $response->status(),
            ]);

            if ($response->successful()) {
                $orders = $response->json();
                if (is_array($orders) && !empty($orders)) {
                    // Extrair status únicos dos pedidos reais
                    $foundStatuses = collect($orders)
                        ->pluck('status')
                        ->filter()
                        ->unique()
                        ->values();

                    // Combinar com status padrão
                    $defaultSlugs = ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'];
                    $allSlugs = $foundStatuses->merge($defaultSlugs)->unique()->values();

                    $statuses = $allSlugs->map(fn($slug) => [
                        'slug' => $slug,
                        'name' => $this->statusSlugToName($slug),
                        'total' => $foundStatuses->filter(fn($s) => $s === $slug)->count() > 0
                            ? collect($orders)->where('status', $slug)->count()
                            : 0,
                    ])->toArray();

                    SystemLog::info('analytics', 'woocommerce.statuses.fetched_from_orders', count($statuses) . " status encontrados via scan de pedidos", [
                        'statuses' => array_map(fn($s) => $s['slug'] . ' (' . $s['total'] . ')', $statuses),
                        'custom_statuses' => $foundStatuses->diff($defaultSlugs)->values()->toArray(),
                    ]);

                    return $statuses;
                }
            }
        } catch (\Throwable $e) {
            SystemLog::warning('analytics', 'woocommerce.statuses.orders_error', "Erro ao buscar pedidos para scan de status: {$e->getMessage()}", [
                'url' => $ordersUrl,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: retornar status padrão do WooCommerce
        SystemLog::warning('analytics', 'woocommerce.statuses.fallback', 'Usando status padrão (nenhum método de API funcionou)', [
            'store_url' => $baseUrl,
        ]);

        return [
            ['slug' => 'pending', 'name' => 'Pendente', 'total' => 0],
            ['slug' => 'processing', 'name' => 'Processando', 'total' => 0],
            ['slug' => 'on-hold', 'name' => 'Aguardando', 'total' => 0],
            ['slug' => 'completed', 'name' => 'Concluído', 'total' => 0],
            ['slug' => 'cancelled', 'name' => 'Cancelado', 'total' => 0],
            ['slug' => 'refunded', 'name' => 'Reembolsado', 'total' => 0],
            ['slug' => 'failed', 'name' => 'Falhou', 'total' => 0],
        ];
    }

    /**
     * Converte slug de status para nome legível
     */
    protected function statusSlugToName(string $slug): string
    {
        $map = [
            'pending' => 'Pendente',
            'processing' => 'Processando',
            'on-hold' => 'Aguardando',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado',
            'failed' => 'Falhou',
            'checkout-draft' => 'Rascunho',
            'trash' => 'Lixeira',
        ];

        return $map[$slug] ?? ucfirst(str_replace(['-', '_'], ' ', $slug));
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

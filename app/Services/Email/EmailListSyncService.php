<?php

namespace App\Services\Email;

use App\Models\EmailContact;
use App\Models\EmailList;
use App\Models\EmailListSource;
use App\Models\AnalyticsConnection;
use App\Models\SystemLog;
use App\Services\Analytics\WooCommerceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

class EmailListSyncService
{
    /**
     * Sincroniza uma fonte externa de contatos
     */
    public function syncSource(EmailListSource $source): array
    {
        $source->markSyncing();

        try {
            $result = match ($source->type) {
                'woocommerce' => $this->syncFromWooCommerce($source),
                'mysql' => $this->syncFromMySQL($source),
                'google_sheets' => $this->syncFromGoogleSheets($source),
                'csv' => $this->syncFromCSV($source),
                default => ['success' => false, 'error' => "Tipo desconhecido: {$source->type}"],
            };

            if ($result['success']) {
                $source->markSuccess($result['synced'] ?? 0);
                $source->list->refreshContactsCount();

                SystemLog::info('email', 'list_sync.success', "Sincronizados {$result['synced']} contatos de {$source->type}", [
                    'source_id' => $source->id,
                    'list_id' => $source->email_list_id,
                    'synced' => $result['synced'],
                    'skipped' => $result['skipped'] ?? 0,
                ]);
            } else {
                $source->markError($result['error'] ?? 'Erro desconhecido');
            }

            return $result;
        } catch (\Throwable $e) {
            $source->markError($e->getMessage());
            Log::error("Email list sync failed", ['source_id' => $source->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Sincroniza contatos do WooCommerce
     */
    private function syncFromWooCommerce(EmailListSource $source): array
    {
        $config = $source->config;
        $connectionId = $config['analytics_connection_id'] ?? null;

        if (!$connectionId) {
            return ['success' => false, 'error' => 'Conexão WooCommerce não configurada.'];
        }

        $connection = AnalyticsConnection::find($connectionId);
        if (!$connection || $connection->platform !== 'woocommerce') {
            return ['success' => false, 'error' => 'Conexão WooCommerce não encontrada.'];
        }

        $wcConfig = $connection->config;
        $storeUrl = rtrim($wcConfig['store_url'], '/');
        $auth = [$wcConfig['consumer_key'], $wcConfig['consumer_secret']];

        $contacts = [];
        $page = 1;
        $perPage = 100;
        $filters = $config['filters'] ?? [];

        do {
            $params = [
                'per_page' => $perPage,
                'page' => $page,
                'role' => 'customer',
                'orderby' => 'registered_date',
                'order' => 'desc',
            ];

            $response = Http::withBasicAuth($auth[0], $auth[1])
                ->timeout(30)
                ->get("{$storeUrl}/wp-json/wc/v3/customers", $params);

            if (!$response->successful()) break;

            $customers = $response->json();
            if (empty($customers)) break;

            foreach ($customers as $customer) {
                $email = $customer['email'] ?? null;
                if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

                // Aplicar filtros
                if (!empty($filters['min_orders']) && ($customer['orders_count'] ?? 0) < $filters['min_orders']) {
                    continue;
                }

                $contacts[] = [
                    'email' => strtolower(trim($email)),
                    'first_name' => $customer['first_name'] ?? $customer['billing']['first_name'] ?? null,
                    'last_name' => $customer['last_name'] ?? $customer['billing']['last_name'] ?? null,
                    'phone' => $customer['billing']['phone'] ?? null,
                    'company' => $customer['billing']['company'] ?? null,
                    'source' => 'woocommerce',
                    'source_id' => (string) ($customer['id'] ?? null),
                    'metadata' => [
                        'wc_customer_id' => $customer['id'] ?? null,
                        'orders_count' => $customer['orders_count'] ?? 0,
                        'total_spent' => $customer['total_spent'] ?? '0',
                        'city' => $customer['billing']['city'] ?? null,
                        'state' => $customer['billing']['state'] ?? null,
                        'country' => $customer['billing']['country'] ?? null,
                    ],
                ];
            }

            $page++;
        } while (count($customers) === $perPage && $page <= 50);

        return $this->upsertContacts($source->list, $contacts);
    }

    /**
     * Sincroniza contatos de banco MySQL externo
     */
    private function syncFromMySQL(EmailListSource $source): array
    {
        $config = $source->config;

        $required = ['host', 'database', 'table', 'email_column'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                return ['success' => false, 'error' => "Campo obrigatório: {$field}"];
            }
        }

        try {
            $pdo = new \PDO(
                "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
                $config['username'] ?? 'root',
                $config['password'] ?? '',
                [\PDO::ATTR_TIMEOUT => 10]
            );

            $columns = [$config['email_column']];
            $nameColumns = $config['name_columns'] ?? [];
            if (!empty($nameColumns['first_name'])) $columns[] = $nameColumns['first_name'];
            if (!empty($nameColumns['last_name'])) $columns[] = $nameColumns['last_name'];
            if (!empty($nameColumns['phone'])) $columns[] = $nameColumns['phone'];
            if (!empty($nameColumns['company'])) $columns[] = $nameColumns['company'];

            $select = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
            $sql = "SELECT {$select} FROM `{$config['table']}`";

            if (!empty($config['where_clause'])) {
                $sql .= " WHERE {$config['where_clause']}";
            }

            $sql .= " LIMIT 50000";

            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $contacts = [];
            foreach ($rows as $row) {
                $email = $row[$config['email_column']] ?? null;
                if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

                $contacts[] = [
                    'email' => strtolower(trim($email)),
                    'first_name' => $row[$nameColumns['first_name'] ?? ''] ?? null,
                    'last_name' => $row[$nameColumns['last_name'] ?? ''] ?? null,
                    'phone' => $row[$nameColumns['phone'] ?? ''] ?? null,
                    'company' => $row[$nameColumns['company'] ?? ''] ?? null,
                    'source' => 'mysql',
                    'source_id' => null,
                ];
            }

            return $this->upsertContacts($source->list, $contacts);
        } catch (\PDOException $e) {
            return ['success' => false, 'error' => 'Erro MySQL: ' . $e->getMessage()];
        }
    }

    /**
     * Sincroniza contatos do Google Sheets
     */
    private function syncFromGoogleSheets(EmailListSource $source): array
    {
        $config = $source->config;

        $spreadsheetId = $config['spreadsheet_id'] ?? null;
        $sheetName = $config['sheet_name'] ?? 'Sheet1';
        $emailColumn = $config['email_column'] ?? 'A';
        $headerRow = $config['header_row'] ?? 1;

        if (!$spreadsheetId) {
            return ['success' => false, 'error' => 'ID da planilha não informado.'];
        }

        // Buscar API key das configurações
        $apiKey = \App\Models\Setting::get('api_keys', 'google_api_key');
        if (!$apiKey) {
            return ['success' => false, 'error' => 'API Key do Google não configurada em Configurações > IA.'];
        }

        $range = urlencode("{$sheetName}!A{$headerRow}:Z10000");
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}?key={$apiKey}";

        $response = Http::get($url);
        if (!$response->successful()) {
            return ['success' => false, 'error' => 'Google Sheets API: ' . ($response->json('error.message') ?? $response->body())];
        }

        $values = $response->json('values', []);
        if (count($values) < 2) {
            return ['success' => false, 'error' => 'Planilha vazia ou com apenas cabeçalho.'];
        }

        // Primeira linha = cabeçalhos
        $headers = array_map('strtolower', array_map('trim', $values[0]));
        $nameColumns = $config['name_columns'] ?? [];

        // Mapear colunas
        $emailIdx = array_search(strtolower($emailColumn), $headers);
        if ($emailIdx === false) {
            // Tentar pelo nome da coluna diretamente
            $emailIdx = array_search('email', $headers);
            if ($emailIdx === false) {
                $emailIdx = 0; // fallback: primeira coluna
            }
        }

        $firstNameIdx = !empty($nameColumns['first_name']) ? array_search(strtolower($nameColumns['first_name']), $headers) : array_search('nome', $headers);
        $lastNameIdx = !empty($nameColumns['last_name']) ? array_search(strtolower($nameColumns['last_name']), $headers) : array_search('sobrenome', $headers);
        $phoneIdx = array_search('telefone', $headers) ?: array_search('phone', $headers);
        $companyIdx = array_search('empresa', $headers) ?: array_search('company', $headers);

        $contacts = [];
        for ($i = 1; $i < count($values); $i++) {
            $row = $values[$i];
            $email = $row[$emailIdx] ?? null;
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

            $contacts[] = [
                'email' => strtolower(trim($email)),
                'first_name' => ($firstNameIdx !== false) ? ($row[$firstNameIdx] ?? null) : null,
                'last_name' => ($lastNameIdx !== false) ? ($row[$lastNameIdx] ?? null) : null,
                'phone' => ($phoneIdx !== false) ? ($row[$phoneIdx] ?? null) : null,
                'company' => ($companyIdx !== false) ? ($row[$companyIdx] ?? null) : null,
                'source' => 'sheets',
                'source_id' => null,
            ];
        }

        return $this->upsertContacts($source->list, $contacts);
    }

    /**
     * Sincroniza contatos de arquivo CSV
     */
    private function syncFromCSV(EmailListSource $source): array
    {
        $config = $source->config;
        $filePath = $config['file_path'] ?? null;

        if (!$filePath || !Storage::disk('local')->exists($filePath)) {
            return ['success' => false, 'error' => 'Arquivo CSV não encontrado.'];
        }

        $mapping = $config['mapping'] ?? [];
        $fullPath = Storage::disk('local')->path($filePath);

        try {
            $csv = Reader::createFromPath($fullPath, 'r');
            $csv->setHeaderOffset(0);

            $contacts = [];
            foreach ($csv->getRecords() as $record) {
                $email = $record[$mapping['email'] ?? 'email'] ?? null;
                if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

                $contacts[] = [
                    'email' => strtolower(trim($email)),
                    'first_name' => $record[$mapping['first_name'] ?? 'first_name'] ?? $record[$mapping['first_name'] ?? 'nome'] ?? null,
                    'last_name' => $record[$mapping['last_name'] ?? 'last_name'] ?? $record[$mapping['last_name'] ?? 'sobrenome'] ?? null,
                    'phone' => $record[$mapping['phone'] ?? 'phone'] ?? $record[$mapping['phone'] ?? 'telefone'] ?? null,
                    'company' => $record[$mapping['company'] ?? 'company'] ?? $record[$mapping['company'] ?? 'empresa'] ?? null,
                    'source' => 'import',
                    'source_id' => null,
                ];
            }

            return $this->upsertContacts($source->list, $contacts);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Erro ao processar CSV: ' . $e->getMessage()];
        }
    }

    /**
     * Importa contatos de upload direto (sem fonte persistente)
     */
    public function importFromUpload(EmailList $list, string $filePath, array $mapping = []): array
    {
        $source = new EmailListSource([
            'email_list_id' => $list->id,
            'type' => 'csv',
            'config' => ['file_path' => $filePath, 'mapping' => $mapping],
        ]);

        return $this->syncFromCSV($source);
    }

    /**
     * Upsert em massa de contatos numa lista
     */
    private function upsertContacts(EmailList $list, array $contactsData): array
    {
        $synced = 0;
        $skipped = 0;
        $brandId = $list->brand_id;

        DB::beginTransaction();
        try {
            foreach (array_chunk($contactsData, 100) as $chunk) {
                foreach ($chunk as $data) {
                    $contact = EmailContact::updateOrCreate(
                        ['brand_id' => $brandId, 'email' => $data['email']],
                        array_filter([
                            'first_name' => $data['first_name'] ?? null,
                            'last_name' => $data['last_name'] ?? null,
                            'phone' => $data['phone'] ?? null,
                            'company' => $data['company'] ?? null,
                            'source' => $data['source'] ?? 'import',
                            'source_id' => $data['source_id'] ?? null,
                            'metadata' => $data['metadata'] ?? null,
                            'subscribed_at' => now(),
                        ], fn($v) => $v !== null)
                    );

                    // Vincular à lista se não estiver
                    $exists = DB::table('email_list_contact')
                        ->where('email_list_id', $list->id)
                        ->where('email_contact_id', $contact->id)
                        ->exists();

                    if (!$exists) {
                        DB::table('email_list_contact')->insert([
                            'email_list_id' => $list->id,
                            'email_contact_id' => $contact->id,
                            'added_at' => now(),
                        ]);
                        $synced++;
                    } else {
                        $skipped++;
                    }
                }
            }

            DB::commit();
            return ['success' => true, 'synced' => $synced, 'skipped' => $skipped, 'total' => $synced + $skipped];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}

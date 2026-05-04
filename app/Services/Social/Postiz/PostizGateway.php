<?php

namespace App\Services\Social\Postiz;

use App\Models\Setting;
use App\Models\SystemLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Cliente HTTP para a API pública do Postiz (https://api.postiz.com/public/v1).
 *
 * Autenticação: header Authorization com API key global (configurada em .env como POSTIZ_API_KEY).
 * Rate limit Postiz Cloud: 30 requisições/hora.
 */
class PostizGateway
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 30,
    ) {}

    /**
     * Resolve credenciais priorizando Settings (front-end) → config/env.
     * Isso permite que o admin configure POSTIZ_API_KEY pela UI de Settings.
     */
    public static function fromConfig(): self
    {
        $settingUrl = self::getSetting('postiz_url');
        $settingKey = self::getSetting('postiz_api_key');

        return new self(
            baseUrl: rtrim($settingUrl ?: config('services.postiz.url', 'https://api.postiz.com/public/v1'), '/'),
            apiKey: $settingKey ?: config('services.postiz.api_key'),
            timeout: (int) config('services.postiz.timeout', 30),
        );
    }

    private static function getSetting(string $key): ?string
    {
        try {
            return Setting::get('oauth', $key);
        } catch (Throwable) {
            return null;
        }
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * GET /integrations — lista todas as integrações conectadas na organização Postiz.
     *
     * @return array<int, array{id:string,name:string,identifier:string,picture:?string,disabled:bool,profile:?string}>
     */
    public function listIntegrations(): array
    {
        $response = $this->client()->get('/integrations');

        if (!$response->successful()) {
            $this->throwApiError('Falha ao listar integrações Postiz', $response);
        }

        return $response->json() ?? [];
    }

    /**
     * GET /social/{integration} — gera URL de autorização OAuth para conectar
     * uma nova conta da plataforma informada.
     *
     * @param string|null $refreshIntegrationId Se informado, refresca token de uma integration existente
     */
    public function generateOAuthUrl(string $platformIdentifier, ?string $refreshIntegrationId = null): string
    {
        $query = $refreshIntegrationId ? ['refresh' => $refreshIntegrationId] : [];
        $response = $this->client()->get("/social/{$platformIdentifier}", $query);

        if (!$response->successful()) {
            $this->throwApiError("Falha ao gerar URL OAuth Postiz para {$platformIdentifier}", $response);
        }

        $url = $response->json('url');
        if (!$url) {
            throw new RuntimeException("Postiz não retornou URL OAuth para {$platformIdentifier}.");
        }

        return $url;
    }

    /**
     * POST /upload-from-url — envia mídia hospedada em URL pública para o Postiz
     * e retorna referência que pode ser usada em createPost().
     *
     * @return array{id:string,path:string}
     */
    public function uploadFromUrl(string $publicUrl): array
    {
        $response = $this->client()->post('/upload-from-url', [
            'url' => $publicUrl,
        ]);

        if (!$response->successful()) {
            $this->throwApiError('Falha ao enviar mídia via URL para Postiz', $response);
        }

        $data = $response->json();
        if (empty($data['id']) || empty($data['path'])) {
            throw new RuntimeException('Postiz upload-from-url não retornou id/path.');
        }

        return ['id' => $data['id'], 'path' => $data['path']];
    }

    /**
     * POST /upload — fallback de upload direto via multipart para quando a
     * mídia não está acessível por URL pública.
     *
     * @param string $filePath Caminho absoluto no disco local
     * @return array{id:string,path:string}
     */
    public function uploadFile(string $filePath, string $fileName = 'media'): array
    {
        $response = $this->client()
            ->attach('file', file_get_contents($filePath), $fileName)
            ->post('/upload');

        if (!$response->successful()) {
            $this->throwApiError('Falha ao upload de arquivo para Postiz', $response);
        }

        $data = $response->json();
        if (empty($data['id']) || empty($data['path'])) {
            throw new RuntimeException('Postiz upload não retornou id/path.');
        }

        return ['id' => $data['id'], 'path' => $data['path']];
    }

    /**
     * POST /posts — cria/agenda um post em uma ou mais integrations.
     *
     * @return array<int, array{postId:string,integration:string}>
     */
    public function createPost(array $payload): array
    {
        $response = $this->client()->post('/posts', $payload);

        if (!$response->successful()) {
            $this->throwApiError('Falha ao criar post no Postiz', $response);
        }

        $body = $response->json();
        return $body['data'] ?? $body ?? [];
    }

    /**
     * GET /analytics/{integrationId}?date=N — analytics agregados da conta.
     *
     * Retorna array de métricas; cada item tem:
     *   - label: nome da métrica (ex: "Followers", "Impressions")
     *   - data: [{ total: "1250", date: "2026-04-15" }, ...]
     *   - percentageChange: float (variação no período)
     *
     * `date` aceita 7, 30 ou 90 dias de lookback.
     *
     * @return array<int, array{label:string, data:array, percentageChange:float|int}>
     */
    public function getPlatformAnalytics(string $integrationId, int $days = 30): array
    {
        $response = $this->client()->get("/analytics/{$integrationId}", ['date' => $days]);

        if (!$response->successful()) {
            $this->throwApiError("Falha ao buscar analytics Postiz da integration {$integrationId}", $response);
        }

        return $response->json() ?? [];
    }

    /**
     * GET /analytics/post/{postId}?date=N — analytics de um post específico.
     * Mesmo formato do getPlatformAnalytics.
     *
     * @return array<int, array{label:string, data:array, percentageChange:float|int}>
     */
    public function getPostAnalytics(string $postId, int $days = 30): array
    {
        $response = $this->client()->get("/analytics/post/{$postId}", ['date' => $days]);

        if (!$response->successful()) {
            $this->throwApiError("Falha ao buscar analytics do post Postiz {$postId}", $response);
        }

        return $response->json() ?? [];
    }

    /**
     * DELETE /integrations/{id} — desconecta uma integration.
     */
    public function deleteIntegration(string $integrationId): void
    {
        $response = $this->client()->delete("/integrations/{$integrationId}");

        if (!$response->successful() && $response->status() !== 404) {
            $this->throwApiError("Falha ao deletar integration Postiz {$integrationId}", $response);
        }
    }

    private function client(): PendingRequest
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('POSTIZ_API_KEY não configurada. Configure em .env ou nas Settings.');
        }

        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'Authorization' => $this->apiKey,
                'Accept' => 'application/json',
            ])
            ->acceptJson()
            ->timeout($this->timeout);
    }

    private function throwApiError(string $prefix, Response $response): never
    {
        $body = $response->json() ?? ['raw' => substr($response->body(), 0, 500)];
        $rawMessage = $response->json('message') ?? $response->json('error') ?? $prefix;

        // Postiz retorna message como array em erros de validação:
        //   { "message": ["posts.0.settings.autoAddMusic must be one of..."] }
        // Achata pra string pra evitar "Array to string conversion" e expor o
        // erro real no PostSchedule.error_message.
        $message = is_array($rawMessage)
            ? implode(' | ', array_map(fn($m) => is_scalar($m) ? (string) $m : json_encode($m), $rawMessage))
            : (string) $rawMessage;

        SystemLog::error('social', 'postiz.api.error', $prefix, [
            'status' => $response->status(),
            'body' => $body,
        ]);

        throw new RuntimeException("{$prefix} (HTTP {$response->status()}): {$message}");
    }
}

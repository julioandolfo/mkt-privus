<?php

namespace App\Http\Controllers;

use App\Models\PostSchedule;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Recebe webhooks do Postiz para atualizar status de publicações em tempo real,
 * substituindo polling. Configure a URL deste endpoint no painel do Postiz em
 * Settings → Webhooks → URL: {APP_URL}/webhook/postiz.
 *
 * Opcionalmente defina `POSTIZ_WEBHOOK_SECRET` no .env (ou em Settings.oauth.postiz_webhook_secret)
 * e configure o mesmo valor no header `X-Postiz-Secret` do webhook para autenticar.
 *
 * Como a API pública do Postiz não documenta o schema de webhook, este handler
 * aceita qualquer formato e tenta correlacionar via `postId`/`id` + `integration`.
 */
class PostizWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        if (!$this->verifySecret($request)) {
            SystemLog::warning('webhook', 'postiz.webhook.unauthorized', 'Webhook Postiz rejeitado: secret inválido', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'invalid secret'], 401);
        }

        try {
            SystemLog::info('webhook', 'postiz.webhook.received', 'Webhook Postiz recebido', [
                'event' => $payload['event'] ?? $payload['type'] ?? null,
                'keys' => array_keys($payload),
            ]);

            $postId = $this->extractPostId($payload);
            if (!$postId) {
                return response()->json(['status' => 'ignored', 'reason' => 'no postId']);
            }

            $schedule = PostSchedule::where('platform_post_id', $postId)->first();

            if (!$schedule) {
                SystemLog::info('webhook', 'postiz.webhook.no_match', "Webhook Postiz sem schedule correspondente", [
                    'postiz_post_id' => $postId,
                ]);
                return response()->json(['status' => 'ignored', 'reason' => 'no matching schedule']);
            }

            $event = strtolower((string) ($payload['event'] ?? $payload['type'] ?? $payload['status'] ?? ''));

            if ($this->isSuccessEvent($event)) {
                $schedule->update([
                    'status' => 'published',
                    'published_at' => now(),
                    'platform_post_url' => $payload['url'] ?? $payload['releaseURL'] ?? $schedule->platform_post_url,
                ]);
                SystemLog::info('webhook', 'postiz.webhook.published', "Schedule #{$schedule->id} marcado como publicado via webhook");
            } elseif ($this->isFailureEvent($event)) {
                $schedule->update([
                    'status' => 'failed',
                    'error_message' => $payload['error'] ?? $payload['message'] ?? 'Falha reportada pelo Postiz',
                ]);
                SystemLog::warning('webhook', 'postiz.webhook.failed', "Schedule #{$schedule->id} marcado como falho via webhook");
            }

            return response()->json(['status' => 'ok', 'schedule_id' => $schedule->id]);
        } catch (Throwable $e) {
            SystemLog::error('webhook', 'postiz.webhook.error', "Erro processando webhook Postiz: {$e->getMessage()}");
            return response()->json(['error' => 'internal error'], 500);
        }
    }

    private function verifySecret(Request $request): bool
    {
        $expected = \App\Models\Setting::get('oauth', 'postiz_webhook_secret')
            ?: config('services.postiz.webhook_secret');

        // Sem secret configurado → fail-closed em produção, aceito em dev.
        if (empty($expected)) {
            return $this->secretMissingIsAllowed();
        }

        $received = $request->header('X-Postiz-Secret') ?? $request->get('secret');
        return hash_equals((string) $expected, (string) $received);
    }

    /**
     * Sem secret configurado o webhook é rejeitado em produção (fail-closed);
     * em dev/local é aceito para facilitar testes.
     */
    private function secretMissingIsAllowed(): bool
    {
        return !app()->environment('production');
    }

    private function extractPostId(array $payload): ?string
    {
        return $payload['postId']
            ?? $payload['post_id']
            ?? $payload['id']
            ?? $payload['data']['postId']
            ?? $payload['data']['id']
            ?? null;
    }

    private function isSuccessEvent(string $event): bool
    {
        return str_contains($event, 'publish')
            || str_contains($event, 'success')
            || $event === 'completed'
            || $event === 'done';
    }

    private function isFailureEvent(string $event): bool
    {
        return str_contains($event, 'fail')
            || str_contains($event, 'error')
            || str_contains($event, 'reject');
    }
}

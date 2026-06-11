<?php

namespace App\Services\Email;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignEvent;
use App\Models\EmailContact;
use App\Models\EmailAsset;
use App\Models\SystemLog;
use App\Jobs\SendCampaignBatchJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

class EmailCampaignService
{
    public function __construct(
        private EmailProviderService $providerService,
        private EmailTrackingService $trackingService,
    ) {}

    /**
     * Prepara a campanha: resolve destinatarios, calcula total
     */
    public function prepareCampaign(EmailCampaign $campaign): int
    {
        $contacts = $this->resolveRecipients($campaign);
        $campaign->update(['total_recipients' => $contacts->count()]);
        return $contacts->count();
    }

    /**
     * Inicia o envio da campanha em batches
     * Respeita o hourly_limit do provedor se configurado
     */
    public function startCampaign(EmailCampaign $campaign): void
    {
        // Limite mensal de envios do plano (no-op com billing desabilitado)
        if ($campaign->brand) {
            app(\App\Services\Billing\UsageService::class)
                ->assertCanSendEmails($campaign->brand, $this->resolveRecipients($campaign)->count());
        }

        $campaign->update([
            'status' => 'sending',
            'started_at' => now(),
        ]);

        $contacts = $this->resolveRecipients($campaign);
        $campaign->update(['total_recipients' => $contacts->count()]);

        // Registrar todos como "queued"
        $events = $contacts->map(fn($contact) => [
            'email_campaign_id' => $campaign->id,
            'email_contact_id' => $contact->id,
            'event_type' => 'queued',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->toArray();

        foreach (array_chunk($events, 500) as $chunk) {
            EmailCampaignEvent::insert($chunk);
        }

        // Configurar batches respeitando quota do provedor
        $provider = $campaign->provider;
        $hourlyLimit = $provider?->hourly_limit;

        if ($hourlyLimit && $hourlyLimit > 0) {
            // Respeitar limite por hora do provedor
            $batchSize = min($hourlyLimit, 100); // máximo 100 por batch
            $delayBetweenBatches = 3600; // 1 hora entre batches (em segundos)

            SystemLog::info('email', 'campaign.rate_limit_enabled', "Modo de respeito à quota ativado: {$hourlyLimit} emails/hora", [
                'campaign_id' => $campaign->id,
                'provider_id' => $provider->id,
                'hourly_limit' => $hourlyLimit,
                'batch_size' => $batchSize,
                'delay_between_batches' => $delayBetweenBatches,
            ]);
        } else {
            // Modo padrão: sem limite de quota
            $sendSpeed = $campaign->getSetting('send_speed', 100);
            $batchSize = min($sendSpeed, 100);
            $delayBetweenBatches = 60; // 1 minuto entre batches
        }

        $contactIds = $contacts->pluck('id')->toArray();
        $totalBatches = ceil(count($contactIds) / $batchSize);
        $estimatedHours = ceil($totalBatches / ($hourlyLimit ? 1 : 60)); // para logging

        foreach (array_chunk($contactIds, $batchSize) as $index => $batchIds) {
            $delay = $index * $delayBetweenBatches;
            SendCampaignBatchJob::dispatch($campaign->id, $batchIds)
                ->delay(now()->addSeconds($delay))
                ->onQueue('email');
        }

        SystemLog::info('email', 'campaign.started', "Campanha \"{$campaign->name}\" iniciada com {$contacts->count()} destinatários", [
            'campaign_id' => $campaign->id,
            'recipients' => $contacts->count(),
            'batches' => $totalBatches,
            'batch_size' => $batchSize,
            'hourly_limit' => $hourlyLimit,
            'estimated_hours' => $hourlyLimit ? ceil($totalBatches) : 'N/A (sem limite)',
        ]);
    }

    /**
     * Pausa o envio da campanha
     */
    public function pauseCampaign(EmailCampaign $campaign): void
    {
        $campaign->update(['status' => 'paused']);
        SystemLog::info('email', 'campaign.paused', "Campanha \"{$campaign->name}\" pausada");
    }

    /**
     * Cancela o envio da campanha
     */
    public function cancelCampaign(EmailCampaign $campaign): void
    {
        $campaign->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);
        SystemLog::info('email', 'campaign.cancelled', "Campanha \"{$campaign->name}\" cancelada");
    }

    /**
     * Processa um batch de envio
     */
    public function processBatch(int $campaignId, array $contactIds): array
    {
        SystemLog::info('email', 'batch.starting', "Iniciando processamento de batch", [
            'campaign_id' => $campaignId,
            'batch_size' => count($contactIds),
            'contact_ids_sample' => array_slice($contactIds, 0, 5), // primeiros 5 para debug
        ]);

        $campaign = EmailCampaign::with('provider')->find($campaignId);
        if (!$campaign || !$campaign->isSending()) {
            $reason = !$campaign ? 'campaign_not_found' : 'campaign_not_sending';
            SystemLog::warning('email', 'batch.aborted', "Batch abortado: {$reason}", [
                'campaign_id' => $campaignId,
                'reason' => $reason,
                'campaign_status' => $campaign?->status,
            ]);
            return ['sent' => 0, 'failed' => 0, 'reason' => $reason];
        }

        $provider = $campaign->provider;
        if (!$provider) {
            SystemLog::error('email', 'batch.no_provider', "Batch abortado: provedor não encontrado", [
                'campaign_id' => $campaignId,
                'provider_id' => $campaign->email_provider_id,
            ]);
            return ['sent' => 0, 'failed' => 0, 'reason' => 'no_provider'];
        }

        SystemLog::info('email', 'batch.provider_ready', "Provedor confirmado: {$provider->name} ({$provider->type})", [
            'campaign_id' => $campaignId,
            'provider_id' => $provider->id,
            'provider_type' => $provider->type,
            'quota_remaining' => $provider->getRemainingQuota(),
        ]);

        // Verificar quotas apenas para log/alerta - NÃO bloquear envio
        // O SendPulse aceita emails mesmo "acima" do limite (processa por minuto)
        $quotaWarning = null;
        if (!$provider->hasQuotaRemaining()) {
            $quotaInfo = $provider->getQuotaInfo();
            if ($quotaInfo['hourly_remaining'] !== null && $quotaInfo['hourly_remaining'] <= 0) {
                $quotaWarning = "Quota por hora atingida ({$provider->hourly_limit}/hora), mas tentando enviar mesmo assim";
            } elseif ($quotaInfo['daily_remaining'] !== null && $quotaInfo['daily_remaining'] <= 0) {
                $quotaWarning = "Quota diária atingida ({$provider->daily_limit}/dia), mas tentando enviar mesmo assim";
            }

            if ($quotaWarning) {
                SystemLog::warning('email', 'campaign.quota_warning', $quotaWarning, [
                    'campaign_id' => $campaign->id,
                    'provider_id' => $provider->id,
                    'quota_info' => $quotaInfo,
                ]);
            }
        }

        $contacts = EmailContact::whereIn('id', $contactIds)
            ->where('status', 'active')
            ->get();

        SystemLog::info('email', 'batch.contacts_loaded', "Contatos carregados: {$contacts->count()} de " . count($contactIds) . " IDs", [
            'campaign_id' => $campaignId,
            'requested_count' => count($contactIds),
            'loaded_count' => $contacts->count(),
            'contact_ids' => $contactIds,
        ]);

        if ($contacts->isEmpty()) {
            SystemLog::warning('email', 'batch.no_active_contacts', "Nenhum contato ativo encontrado para o batch", [
                'campaign_id' => $campaignId,
                'contact_ids' => $contactIds,
            ]);
            return ['sent' => 0, 'failed' => 0, 'reason' => 'no_active_contacts'];
        }

        $sent = 0;
        $failed = 0;

        // Determinar o email do remetente correto (especialmente para SendPulse)
        $fromEmail = $this->resolveFromEmail($campaign);
        $fromName = $campaign->from_name ?: $provider->getFromName() ?: config('app.name');

        foreach ($contacts as $contact) {
            // Verificar se este contato já foi enviado (evita duplicados)
            $alreadySent = EmailCampaignEvent::where('email_campaign_id', $campaign->id)
                ->where('email_contact_id', $contact->id)
                ->where('event_type', 'sent')
                ->exists();

            if ($alreadySent) {
                SystemLog::info('email', 'batch.skip_already_sent', "Pulando {$contact->email} - já foi enviado anteriormente", [
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                ]);
                // Não incrementa $sent aqui - este email já foi contado antes
                continue;
            }

            $html = $this->renderForContact($campaign, $contact);
            $htmlSize = strlen($html);

            // Verificar se há imagens base64 no HTML gerado
            $hasBase64Images = str_contains($html, 'data:image');
            $base64Count = substr_count($html, 'data:image');

            SystemLog::info('email', 'batch.rendered', "HTML renderizado para envio", [
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'contact_email' => $contact->email,
                'html_size' => $htmlSize,
                'has_base64_images' => $hasBase64Images,
                'base64_image_count' => $base64Count,
                'html_sample' => substr($html, 0, 500), // Primeiros 500 caracteres
            ]);

            // Delay entre envios para respeitar o rate limit da API do SendPulse (max 30 req/min)
            // 2.5s entre cada = ~24 req/min (margem de segurança)
            if ($sent > 0 || $failed > 0) {
                usleep(2500000); // 2.5 segundos
            }

            SystemLog::info('email', 'batch.sending_contact', "Enviando email para {$contact->email}", [
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'contact_email' => $contact->email,
            ]);

            try {
                $result = $this->providerService->send(
                    $provider,
                    $contact->email,
                    $this->renderMergeTags($campaign->subject, $contact),
                    $html,
                    $fromName,
                    $fromEmail,
                    $campaign->reply_to,
                    [
                        'X-Campaign-ID' => (string) $campaign->id,
                        'X-Contact-ID' => (string) $contact->id,
                        'List-Unsubscribe' => '<' . $this->trackingService->generateUnsubscribeUrl($campaign->id, $contact->id) . '>',
                    ]
                );

                SystemLog::info('email', 'batch.send_result', "Resultado do envio para {$contact->email}", [
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'contact_email' => $contact->email,
                    'result_success' => $result['success'] ?? false,
                    'result_message_id' => $result['message_id'] ?? null,
                    'result_error' => $result['error'] ?? null,
                    'result_full' => $result,
                ]);

                if ($result['success']) {
                    EmailCampaignEvent::create([
                        'email_campaign_id' => $campaign->id,
                        'email_contact_id' => $contact->id,
                        'event_type' => 'sent',
                        'occurred_at' => now(),
                        'metadata' => ['message_id' => $result['message_id'] ?? null],
                    ]);
                    $sent++;

                    // Incrementar contador de envios do provedor
                    try {
                        $provider->incrementSendCount();
                    } catch (\Throwable $e) {
                        // Ignorar erro de incremento
                    }
                } else {
                    $errorMessage = $result['error'] ?? 'Unknown error from provider';

                    // Verificar se é erro de quota (limite diário/hora atingido)
                    $isQuotaError = $this->isQuotaError($errorMessage);

                    if ($isQuotaError) {
                        // Erro de quota: não marcar como failed, apenas logar
                        // O email permanece na fila (queued) para próxima tentativa
                        SystemLog::warning('email', 'batch.quota_exceeded', "Quota excedida para {$contact->email}: {$errorMessage}", [
                            'campaign_id' => $campaign->id,
                            'contact_id' => $contact->id,
                            'error' => $errorMessage,
                            'provider_id' => $provider->id,
                        ]);

                        // NÃO criar evento 'failed' - deixa na fila para reprocessar
                        // NÃO incrementa $failed - não conta como falha permanente
                    } else {
                        // Erro definitivo: marcar como failed
                        EmailCampaignEvent::create([
                            'email_campaign_id' => $campaign->id,
                            'email_contact_id' => $contact->id,
                            'event_type' => 'failed',
                            'occurred_at' => now(),
                            'metadata' => ['error' => $errorMessage],
                        ]);
                        $failed++;
                    }
                }
            } catch (\Throwable $e) {
                SystemLog::error('email', 'batch.send_exception', "Exceção ao enviar para {$contact->email}: {$e->getMessage()}", [
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'contact_email' => $contact->email,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                EmailCampaignEvent::create([
                    'email_campaign_id' => $campaign->id,
                    'email_contact_id' => $contact->id,
                    'event_type' => 'failed',
                    'occurred_at' => now(),
                    'metadata' => ['error' => 'Exception: ' . $e->getMessage()],
                ]);
                $failed++;
            }
        }

        // Contatos que ainda estão só como 'queued' (sem sent/failed) = pendentes reais
        $pendingContactIds = EmailCampaignEvent::where('email_campaign_id', $campaign->id)
            ->where('event_type', 'queued')
            ->whereNotIn('email_contact_id', function ($q) use ($campaign) {
                $q->select('email_contact_id')
                  ->from('email_campaign_events')
                  ->where('email_campaign_id', $campaign->id)
                  ->whereIn('event_type', ['sent', 'failed']);
            })
            ->distinct()
            ->pluck('email_contact_id')
            ->toArray();

        $totalQueued    = $campaign->events()->where('event_type', 'queued')->distinct('email_contact_id')->count('email_contact_id');
        $totalProcessed = $campaign->events()->whereIn('event_type', ['sent', 'failed'])->distinct('email_contact_id')->count('email_contact_id');
        $totalPending   = count($pendingContactIds);

        SystemLog::info('email', 'batch.completed', "Batch finalizado", [
            'campaign_id'      => $campaign->id,
            'sent'             => $sent,
            'failed'           => $failed,
            'total_queued'     => $totalQueued,
            'total_processed'  => $totalProcessed,
            'total_pending'    => $totalPending,
            'campaign_finished' => $totalPending === 0,
        ]);

        if ($totalPending === 0) {
            // Todos processados — campanha concluída
            $campaign->update(['status' => 'sent', 'completed_at' => now()]);
            $campaign->refreshStats();

            SystemLog::info('email', 'campaign.finished', "Campanha \"{$campaign->name}\" finalizada!", [
                'campaign_id' => $campaign->id,
                'total_sent'  => $sent,
                'total_failed' => $failed,
            ]);
        } elseif ($totalPending > 0 && $sent === 0 && $failed === 0) {
            // Este batch não conseguiu enviar nenhum (erros temporários)
            // Agendar retry para os pendentes após 1 hora (respeita rate limit)
            $provider  = $campaign->provider;
            $batchSize = $provider?->hourly_limit ? min(50, $provider->hourly_limit) : 50;
            $chunks    = array_chunk($pendingContactIds, $batchSize);

            SystemLog::warning('email', 'batch.retry_scheduled', "Batch sem envios — agendando retry para {$totalPending} contatos pendentes", [
                'campaign_id'  => $campaign->id,
                'pending'      => $totalPending,
                'retry_batches' => count($chunks),
                'retry_in'     => '60 minutes',
            ]);

            foreach ($chunks as $i => $chunk) {
                \App\Jobs\SendCampaignBatchJob::dispatch($campaign->id, $chunk)
                    ->onQueue('email')
                    ->delay(now()->addMinutes(60 + ($i * 5)));
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Renderiza o HTML para um contato especifico (merge tags)
     */
    public function renderForContact(EmailCampaign $campaign, EmailContact $contact): string
    {
        $html = $campaign->html_content ?? '';
        $originalLength = strlen($html);

        SystemLog::info('email', 'render.start', "Iniciando renderização para contato", [
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'contact_email' => $contact->email,
            'html_length' => $originalLength,
        ]);

        // Inline CSS (<style> no <head> → atributos style="" em cada elemento)
        // Necessário porque clientes de email (Gmail, Outlook) removem <head>/<style>
        $html = $this->inlineCss($html);

        // Converter caminhos relativos (/storage/...) para URLs absolutas
        // NÃO usar base64 — provedores como SendPulse removem imagens base64 do email
        $html = $this->ensureAbsoluteImageUrls($html);

        SystemLog::info('email', 'render.images_resolved', "URLs de imagens resolvidas para absolutas", [
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
        ]);

        // Adicionar tracking pixel
        $trackOpen = $campaign->getSetting('track_opens', true);
        if ($trackOpen) {
            $pixel = $this->trackingService->generateTrackingPixel($campaign->id, $contact->id);
            $html = str_replace('</body>', $pixel . '</body>', $html);
        }

        // Substituir links para tracking
        $trackClicks = $campaign->getSetting('track_clicks', true);
        if ($trackClicks) {
            $html = $this->trackingService->wrapLinks($html, $campaign->id, $contact->id);
        }

        // Substituir merge tags
        $html = $this->renderMergeTags($html, $contact);

        SystemLog::debug('email', 'render.complete', "Renderização concluída", [
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'original_length' => $originalLength,
            'final_length' => strlen($html),
        ]);

        return $html;
    }

    /**
     * Retorna o email do remetente correto para envio.
     * Para SendPulse, SEMPRE usa o email configurado no provedor para evitar erros.
     */
    public function resolveFromEmail(EmailCampaign $campaign): string
    {
        $provider = $campaign->provider;

        if (!$provider) {
            return $campaign->from_email ?: config('mail.from.address');
        }

        // SendPulse: sempre usar o email verificado do provedor
        if ($provider->type === 'sendpulse') {
            $configFromEmail = $provider->config['from_email'] ?? $provider->config['from_address'] ?? null;
            if ($configFromEmail) {
                return $configFromEmail;
            }
        }

        return $campaign->from_email ?: $provider->getFromEmail() ?: config('mail.from.address');
    }

    /**
     * Converte CSS de <style> tags para inline styles nos elementos.
     * Essencial para compatibilidade com clientes de email.
     */
    private function inlineCss(string $html): string
    {
        if (empty($html)) {
            return $html;
        }

        try {
            $css = '';
            if (preg_match('/<style[^>]*>(.*?)<\/style>/si', $html, $matches)) {
                $css = $matches[1];
            }

            // Remove tags <img> inteiras antes do inliner e substitui por comentários HTML.
            // O DOMDocument interno do CssToInlineStyles remove atributos src de <img>,
            // e nem placeholders sobrevivem. Comentários HTML passam intactos.
            $imgMap = [];
            $html = preg_replace_callback('/<img[^>]*\/?>/i', function ($m) use (&$imgMap) {
                $key = '<!--IMGHOLD' . count($imgMap) . '-->';
                $imgMap[$key] = $m[0];
                return $key;
            }, $html);

            $inliner = new CssToInlineStyles();
            $inlined = $inliner->convert($html, $css) ?: $html;

            // Restaura as tags <img> originais intactas
            foreach ($imgMap as $key => $originalTag) {
                $inlined = str_replace($key, $originalTag, $inlined);
            }

            return $inlined;
        } catch (\Throwable $e) {
            Log::warning('Email CSS inlining failed', ['error' => $e->getMessage()]);
            return $html;
        }
    }

    /**
     * Substitui merge tags no conteudo
     */
    /**
     * Garante que todas as imagens tenham URLs absolutas (não base64)
     * Provedores como SendPulse removem imagens base64 do HTML
     */
    private function ensureAbsoluteImageUrls(string $html): string
    {
        if (empty($html)) {
            return $html;
        }

        $appUrl = rtrim(config('app.url'), '/');

        // Converte caminhos relativos /storage/... para URLs absolutas
        $html = preg_replace_callback(
            '/(<img[^>]+src=["\'])\/storage\/([^"\']+)(["\'][^>]*>)/i',
            function ($matches) use ($appUrl) {
                return $matches[1] . $appUrl . '/storage/' . $matches[2] . $matches[3];
            },
            $html
        );

        return $html;
    }

    public function renderMergeTags(string $content, EmailContact $contact): string
    {
        $replacements = [
            '{{first_name}}' => $contact->first_name ?? '',
            '{{last_name}}' => $contact->last_name ?? '',
            '{{full_name}}' => $contact->full_name,
            '{{email}}' => $contact->email,
            '{{company}}' => $contact->company ?? '',
            '{{phone}}' => $contact->phone ?? '',
        ];

        // Merge tags de metadata
        if ($contact->metadata) {
            foreach ($contact->metadata as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $replacements["{{meta.{$key}}}"] = (string) $value;
                }
            }
        }

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * Converte imagens em URLs para base64 inline (data URI).
     * Isso garante que as imagens sejam exibidas mesmo sem acesso externo ao storage.
     */
    public function embedImagesAsBase64(string $html): string
    {
        if (empty($html)) {
            return $html;
        }

        // Padrão para encontrar imagens com src
        $pattern = '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i';
        
        $processedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        $result = preg_replace_callback($pattern, function ($matches) use (&$processedCount, &$skippedCount, &$failedCount) {
            $originalTag = $matches[0];
            $src = $matches[1];

            SystemLog::info('email', 'render.found_image', "Tag img encontrada", ['src' => substr($src, 0, 100)]);

            // Verificar se é SVG - avisar que não é suportado
            if (str_ends_with(strtolower($src), '.svg') || str_contains(strtolower($src), '.svg?')) {
                SystemLog::warning('email', 'render.svg_detected', "Imagem SVG detectada - não será convertida (formato não suportado em emails)", [
                    'src' => $src,
                ]);
                $failedCount++;
                return $originalTag; // Mantém original mas não converte
            }

            // Se já é base64, não processa
            if (str_starts_with($src, 'data:')) {
                $skippedCount++;
                SystemLog::info('email', 'render.skip_base64', "Pulando imagem base64");
                return $originalTag;
            }

            // Se é URL externa (http/https), tenta baixar
            if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
                $base64 = $this->convertUrlToBase64($src);
                if ($base64) {
                    $processedCount++;
                    return str_replace($src, $base64, $originalTag);
                }
                $failedCount++;
                return $originalTag;
            }

            // Se é caminho relativo do storage (ex: /storage/email-assets/...)
            if (str_starts_with($src, '/storage/')) {
                $path = str_replace('/storage/', '', $src);
                $base64 = $this->convertStoragePathToBase64($path);
                if ($base64) {
                    $processedCount++;
                    return str_replace($src, $base64, $originalTag);
                }
                $failedCount++;
                return $originalTag;
            }

            $skippedCount++;
            return $originalTag;
        }, $html);

        SystemLog::info('email', 'render.embed_images', "Imagens convertidas para base64", [
            'processed' => $processedCount,
            'skipped' => $skippedCount,
            'failed' => $failedCount,
        ]);

        return $result;
    }

    /**
     * Converte uma URL para base64 data URI
     */
    private function convertUrlToBase64(string $url): ?string
    {
        try {
            // Se é uma URL local do nosso storage, extrai o path
            $appUrl = config('app.url');
            if (str_starts_with($url, $appUrl)) {
                $relativePath = str_replace($appUrl . '/storage/', '', $url);
                return $this->convertStoragePathToBase64($relativePath);
            }

            // Para URLs externas (como WooCommerce), baixa o conteúdo
            $content = file_get_contents($url);
            if (!$content) {
                return null;
            }

            $mimeType = $this->getMimeTypeFromContent($content) ?? 'image/jpeg';

            // Verificar se é SVG - não é suportado pela maioria dos clientes de email
            if ($mimeType === 'image/svg+xml' || str_ends_with(strtolower($url), '.svg')) {
                SystemLog::warning('email', 'render.svg_not_supported_url', "Imagem SVG de URL externa não é suportada em emails.", [
                    'url' => $url,
                    'mime_type' => $mimeType,
                ]);
                return null;
            }

            $base64 = base64_encode($content);

            return "data:{$mimeType};base64,{$base64}";
        } catch (\Throwable $e) {
            Log::warning('Failed to convert URL to base64', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Converte um path do storage para base64 data URI
     */
    private function convertStoragePathToBase64(string $path): ?string
    {
        try {
            SystemLog::info('email', 'render.convert_storage', "Convertendo path para base64", ['path' => $path]);

            if (!Storage::disk('public')->exists($path)) {
                SystemLog::warning('email', 'render.storage_not_found', "Arquivo não encontrado no storage", ['path' => $path]);
                return null;
            }

            $content = Storage::disk('public')->get($path);
            $mimeType = Storage::disk('public')->mimeType($path) ?? 'image/jpeg';

            // Verificar se é SVG - não é suportado pela maioria dos clientes de email
            if ($mimeType === 'image/svg+xml' || str_ends_with(strtolower($path), '.svg')) {
                SystemLog::warning('email', 'render.svg_not_supported', "Imagem SVG não é suportada em emails. Converta para PNG/JPEG antes de fazer upload.", [
                    'path' => $path,
                    'mime_type' => $mimeType,
                ]);
                // Retorna null para não incluir a imagem quebrada no email
                return null;
            }

            $base64 = base64_encode($content);

            SystemLog::info('email', 'render.base64_created', "Imagem convertida para base64", [
                'path' => $path,
                'mime_type' => $mimeType,
                'size' => strlen($content),
                'base64_length' => strlen($base64),
            ]);

            return "data:{$mimeType};base64,{$base64}";
        } catch (\Throwable $e) {
            SystemLog::error('email', 'render.base64_failed', "Erro ao converter para base64", [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Detecta mime type a partir do conteúdo binário
     */
    private function getMimeTypeFromContent(string $content): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);

        // Limpa charset se presente
        if ($mimeType && str_contains($mimeType, ';')) {
            $mimeType = explode(';', $mimeType)[0];
        }

        return $mimeType ?: null;
    }

    /**
     * Verifica se o erro é relacionado a quota (limite diário/hora atingido)
     */
    private function isQuotaError(string $errorMessage): bool
    {
        $quotaKeywords = [
            'limite diário',
            'limite diario',
            'daily limit',
            'quota exceeded',
            'quota excedida',
            'limit reached',
            'limite atingido',
            'too many requests',
            'rate limit',
            'limite de envio',
            'maximum limit',
            'internal server error',
            'interval server error',
            'server error',
            '429',
            '403',
            '500',
            '502',
            '503',
        ];

        $errorLower = strtolower($errorMessage);

        foreach ($quotaKeywords as $keyword) {
            if (str_contains($errorLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve destinatarios: include lists - exclude lists, deduplicados
     */
    private function resolveRecipients(EmailCampaign $campaign): Collection
    {
        $includeLists = $campaign->includeLists()->pluck('email_lists.id');
        $excludeLists = $campaign->excludeLists()->pluck('email_lists.id');

        $query = EmailContact::query()
            ->where('status', 'active')
            ->whereExists(function ($sub) use ($includeLists) {
                $sub->select(DB::raw(1))
                    ->from('email_list_contact')
                    ->whereColumn('email_list_contact.email_contact_id', 'email_contacts.id')
                    ->whereIn('email_list_contact.email_list_id', $includeLists);
            });

        if ($excludeLists->isNotEmpty()) {
            $query->whereNotExists(function ($sub) use ($excludeLists) {
                $sub->select(DB::raw(1))
                    ->from('email_list_contact')
                    ->whereColumn('email_list_contact.email_contact_id', 'email_contacts.id')
                    ->whereIn('email_list_contact.email_list_id', $excludeLists);
            });
        }

        return $query->get();
    }

    /**
     * Duplica uma campanha
     */
    public function duplicate(EmailCampaign $campaign): EmailCampaign
    {
        $new = $campaign->replicate(['status', 'started_at', 'completed_at', 'scheduled_at',
            'total_recipients', 'total_sent', 'total_delivered', 'total_bounced',
            'total_opened', 'total_clicked', 'total_unsubscribed', 'total_complained',
            'unique_opens', 'unique_clicks']);

        $new->name = $campaign->name . ' (Cópia)';
        $new->status = 'draft';
        $new->save();

        // Copiar listas vinculadas
        foreach ($campaign->lists as $list) {
            $new->lists()->attach($list->id, ['type' => $list->pivot->type]);
        }

        return $new;
    }
}

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
     */
    public function startCampaign(EmailCampaign $campaign): void
    {
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

        // Despachar jobs em batches
        $sendSpeed = $campaign->getSetting('send_speed', 100); // emails por batch
        $batchSize = min($sendSpeed, 100);
        $contactIds = $contacts->pluck('id')->toArray();

        foreach (array_chunk($contactIds, $batchSize) as $index => $batchIds) {
            $delay = $index * 60; // 1 minuto entre batches
            SendCampaignBatchJob::dispatch($campaign->id, $batchIds)
                ->delay(now()->addSeconds($delay))
                ->onQueue('email');
        }

        SystemLog::info('email', 'campaign.started', "Campanha \"{$campaign->name}\" iniciada com {$contacts->count()} destinatários", [
            'campaign_id' => $campaign->id,
            'recipients' => $contacts->count(),
            'batches' => ceil(count($contactIds) / $batchSize),
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
        $campaign = EmailCampaign::with('provider')->find($campaignId);
        if (!$campaign || !$campaign->isSending()) {
            return ['sent' => 0, 'failed' => 0, 'reason' => 'campaign_not_sending'];
        }

        $provider = $campaign->provider;
        if (!$provider) {
            return ['sent' => 0, 'failed' => 0, 'reason' => 'no_provider'];
        }

        // Verificar quotas antes de começar
        if (!$provider->hasQuotaRemaining()) {
            $quotaInfo = $provider->getQuotaInfo();
            $errorMsg = 'Limite de envios atingido';

            if ($quotaInfo['hourly_remaining'] !== null && $quotaInfo['hourly_remaining'] <= 0) {
                $errorMsg = "Limite por hora atingido ({$provider->hourly_limit} emails/hora). Aguarde para continuar.";
            } elseif ($quotaInfo['daily_remaining'] !== null && $quotaInfo['daily_remaining'] <= 0) {
                $errorMsg = "Limite diário atingido ({$provider->daily_limit} emails/dia).";
            }

            SystemLog::warning('email', 'campaign.quota_exceeded', $errorMsg, [
                'campaign_id' => $campaign->id,
                'provider_id' => $provider->id,
                'quota_info' => $quotaInfo,
            ]);

            // Marcar todos como failed por quota
            foreach ($contactIds as $contactId) {
                EmailCampaignEvent::create([
                    'email_campaign_id' => $campaign->id,
                    'email_contact_id' => $contactId,
                    'event_type' => 'failed',
                    'occurred_at' => now(),
                    'metadata' => ['error' => $errorMsg, 'reason' => 'quota_exceeded'],
                ]);
            }

            return ['sent' => 0, 'failed' => count($contactIds), 'reason' => 'quota_exceeded', 'error' => $errorMsg];
        }

        $contacts = EmailContact::whereIn('id', $contactIds)
            ->where('status', 'active')
            ->get();

        $sent = 0;
        $failed = 0;

        // Determinar o email do remetente correto (especialmente para SendPulse)
        $fromEmail = $this->resolveFromEmail($campaign);
        $fromName = $campaign->from_name ?: $provider->getFromName() ?: config('app.name');

        foreach ($contacts as $contact) {
            // Verificar quota a cada envio (pode ter sido esgotada no meio do batch)
            if (!$provider->hasQuotaRemaining()) {
                $remainingContacts = count($contacts) - $sent - $failed;
                $errorMsg = 'Limite de envios atingido durante o processamento do batch';

                SystemLog::warning('email', 'campaign.quota_exceeded_mid_batch', $errorMsg, [
                    'campaign_id' => $campaign->id,
                    'provider_id' => $provider->id,
                    'contacts_remaining' => $remainingContacts,
                ]);

                // Marcar restantes como failed
                for ($i = 0; $i < $remainingContacts; $i++) {
                    EmailCampaignEvent::create([
                        'email_campaign_id' => $campaign->id,
                        'email_contact_id' => $contact->id,
                        'event_type' => 'failed',
                        'occurred_at' => now(),
                        'metadata' => ['error' => $errorMsg, 'reason' => 'quota_exceeded'],
                    ]);
                    $failed++;
                }
                break;
            }

            $html = $this->renderForContact($campaign, $contact);

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

            if ($result['success']) {
                EmailCampaignEvent::create([
                    'email_campaign_id' => $campaign->id,
                    'email_contact_id' => $contact->id,
                    'event_type' => 'sent',
                    'occurred_at' => now(),
                    'metadata' => ['message_id' => $result['message_id'] ?? null],
                ]);
                $sent++;
            } else {
                EmailCampaignEvent::create([
                    'email_campaign_id' => $campaign->id,
                    'email_contact_id' => $contact->id,
                    'event_type' => 'failed',
                    'occurred_at' => now(),
                    'metadata' => ['error' => $result['error'] ?? 'Unknown'],
                ]);
                $failed++;
            }
        }

        // Verificar se campanha terminou
        $totalQueued = $campaign->events()->where('event_type', 'queued')->count();
        $totalProcessed = $campaign->events()->whereIn('event_type', ['sent', 'failed'])->count();

        if ($totalProcessed >= $totalQueued) {
            $campaign->update([
                'status' => 'sent',
                'completed_at' => now(),
            ]);
            $campaign->refreshStats();
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Renderiza o HTML para um contato especifico (merge tags)
     */
    public function renderForContact(EmailCampaign $campaign, EmailContact $contact): string
    {
        $html = $campaign->html_content ?? '';

        // Inline CSS (<style> no <head> → atributos style="" em cada elemento)
        // Necessário porque clientes de email (Gmail, Outlook) removem <head>/<style>
        $html = $this->inlineCss($html);

        // Converter imagens para base64 inline (garante que funcionem no email)
        $html = $this->embedImagesAsBase64($html);

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
            // Extrair CSS do <style> tag
            $css = '';
            if (preg_match('/<style[^>]*>(.*?)<\/style>/si', $html, $matches)) {
                $css = $matches[1];
            }

            $inliner = new CssToInlineStyles();
            $inlined = $inliner->convert($html, $css);

            return $inlined ?: $html;
        } catch (\Throwable $e) {
            Log::warning('Email CSS inlining failed', ['error' => $e->getMessage()]);
            return $html;
        }
    }

    /**
     * Substitui merge tags no conteudo
     */
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

        return preg_replace_callback($pattern, function ($matches) {
            $originalTag = $matches[0];
            $src = $matches[1];

            // Se já é base64, não processa
            if (str_starts_with($src, 'data:')) {
                return $originalTag;
            }

            // Se é URL externa (http/https), tenta baixar
            if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
                $base64 = $this->convertUrlToBase64($src);
                if ($base64) {
                    return str_replace($src, $base64, $originalTag);
                }
                return $originalTag;
            }

            // Se é caminho relativo do storage (ex: /storage/email-assets/...)
            if (str_starts_with($src, '/storage/')) {
                $path = str_replace('/storage/', '', $src);
                $base64 = $this->convertStoragePathToBase64($path);
                if ($base64) {
                    return str_replace($src, $base64, $originalTag);
                }
            }

            return $originalTag;
        }, $html);
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
            if (!Storage::disk('public')->exists($path)) {
                return null;
            }

            $content = Storage::disk('public')->get($path);
            $mimeType = Storage::disk('public')->mimeType($path) ?? 'image/jpeg';
            $base64 = base64_encode($content);

            return "data:{$mimeType};base64,{$base64}";
        } catch (\Throwable $e) {
            Log::warning('Failed to convert storage path to base64', ['path' => $path, 'error' => $e->getMessage()]);
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

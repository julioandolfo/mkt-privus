<?php

namespace App\Services\Email;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignEvent;
use App\Models\EmailContact;
use App\Models\SystemLog;
use App\Jobs\SendCampaignBatchJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        $contacts = EmailContact::whereIn('id', $contactIds)
            ->where('status', 'active')
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($contacts as $contact) {
            $html = $this->renderForContact($campaign, $contact);

            $result = $this->providerService->send(
                $provider,
                $contact->email,
                $this->renderMergeTags($campaign->subject, $contact),
                $html,
                $campaign->from_name,
                $campaign->from_email,
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

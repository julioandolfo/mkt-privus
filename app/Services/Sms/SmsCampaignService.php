<?php

namespace App\Services\Sms;

use App\Models\EmailContact;
use App\Models\EmailProvider;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignEvent;
use App\Models\SmsTemplate;
use App\Models\SystemLog;
use App\Jobs\SendSmsCampaignBatchJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SmsCampaignService
{
    private SmsProviderService $smsProvider;

    public function __construct(SmsProviderService $smsProvider)
    {
        $this->smsProvider = $smsProvider;
    }

    /**
     * Calcula destinatários e salva total_recipients
     */
    public function calculateRecipients(SmsCampaign $campaign): int
    {
        $recipients = $this->getRecipientQuery($campaign)->count();
        $campaign->update(['total_recipients' => $recipients]);
        return $recipients;
    }

    /**
     * Obtém query de contatos destinatários (com telefone válido)
     */
    public function getRecipientQuery(SmsCampaign $campaign)
    {
        $includeListIds = $campaign->includeLists()->pluck('email_lists.id')->toArray();
        $excludeListIds = $campaign->excludeLists()->pluck('email_lists.id')->toArray();

        $query = EmailContact::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->where('is_subscribed', true)
            ->where(function ($q) {
                $q->whereNull('unsubscribed_at')
                  ->orWhere('unsubscribed_at', '>', now());
            });

        if (!empty($includeListIds)) {
            $query->whereHas('lists', fn($q) => $q->whereIn('email_lists.id', $includeListIds));
        }

        if (!empty($excludeListIds)) {
            $query->whereDoesntHave('lists', fn($q) => $q->whereIn('email_lists.id', $excludeListIds));
        }

        // Excluir contatos que fizeram opt-out de SMS (metadata)
        $query->where(function ($q) {
            $q->whereNull('metadata->sms_optout')
              ->orWhere('metadata->sms_optout', false);
        });

        return $query;
    }

    /**
     * Estima custo da campanha
     */
    public function estimateCost(SmsCampaign $campaign): array
    {
        $recipients = $this->calculateRecipients($campaign);
        $segments = SmsTemplate::calculateSegments($campaign->body ?? '');

        return $this->smsProvider->estimateCost($recipients, $segments);
    }

    /**
     * Inicia envio da campanha
     */
    public function startCampaign(SmsCampaign $campaign): bool
    {
        if (!$campaign->canSend()) {
            Log::warning('SMS Campaign cannot be sent', ['campaign_id' => $campaign->id]);
            return false;
        }

        $campaign->update([
            'status' => 'sending',
            'started_at' => now(),
        ]);

        SystemLog::create([
            'level' => 'info',
            'source' => 'sms_campaign',
            'message' => "Campanha SMS '{$campaign->name}' iniciada",
            'context' => [
                'campaign_id' => $campaign->id,
                'total_recipients' => $campaign->total_recipients,
            ],
        ]);

        // Enviar em lotes de 100
        $batchSize = 100;
        $recipients = $this->getRecipientQuery($campaign)
            ->select('id')
            ->pluck('id')
            ->toArray();

        $batches = array_chunk($recipients, $batchSize);

        foreach ($batches as $index => $batchIds) {
            SendSmsCampaignBatchJob::dispatch($campaign->id, $batchIds, $index)
                ->delay(now()->addSeconds($index * 5)); // 5s entre lotes
        }

        return true;
    }

    /**
     * Envia um lote de SMS
     */
    public function sendBatch(SmsCampaign $campaign, array $contactIds): array
    {
        $provider = $campaign->provider;
        if (!$provider || !$provider->is_active) {
            return ['success' => false, 'error' => 'Provedor SMS inativo'];
        }

        $contacts = EmailContact::whereIn('id', $contactIds)->get();
        $results = ['sent' => 0, 'failed' => 0, 'errors' => []];

        // Preparar body com opt-out
        $baseBody = $campaign->body;
        $settings = $campaign->settings ?? [];

        // Adicionar opt-out LGPD se habilitado
        if (!isset($settings['skip_optout']) || !$settings['skip_optout']) {
            $optOutText = $settings['optout_text'] ?? 'Resp. SAIR p/ cancelar';
            $baseBody = $this->smsProvider->appendOptOut($baseBody, $optOutText);
        }

        foreach ($contacts as $contact) {
            if (!$contact->phone) continue;

            try {
                // Substituir merge tags por contato
                $personalizedBody = $this->smsProvider->replaceMergeTags($baseBody, [
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                    'company' => $contact->metadata['company'] ?? '',
                ]);

                $result = $this->smsProvider->sendSms(
                    $provider,
                    $contact->phone,
                    $personalizedBody,
                    $campaign->sender_name
                );

                if ($result['success']) {
                    SmsCampaignEvent::create([
                        'sms_campaign_id' => $campaign->id,
                        'email_contact_id' => $contact->id,
                        'phone' => $contact->phone,
                        'event_type' => 'sent',
                        'metadata' => ['campaign_id' => $result['campaign_id'] ?? null],
                        'occurred_at' => now(),
                    ]);
                    $results['sent']++;
                } else {
                    SmsCampaignEvent::create([
                        'sms_campaign_id' => $campaign->id,
                        'email_contact_id' => $contact->id,
                        'phone' => $contact->phone,
                        'event_type' => 'failed',
                        'metadata' => ['error' => $result['error']],
                        'occurred_at' => now(),
                    ]);
                    $results['failed']++;
                    $results['errors'][] = ['phone' => $contact->phone, 'error' => $result['error']];
                }
            } catch (\Throwable $e) {
                SmsCampaignEvent::create([
                    'sms_campaign_id' => $campaign->id,
                    'email_contact_id' => $contact->id,
                    'phone' => $contact->phone,
                    'event_type' => 'failed',
                    'metadata' => ['error' => $e->getMessage()],
                    'occurred_at' => now(),
                ]);
                $results['failed']++;
                $results['errors'][] = ['phone' => $contact->phone, 'error' => $e->getMessage()];
            }

            // Rate limit: pequena pausa entre envios individuais
            usleep(50000); // 50ms
        }

        // Atualizar stats da campanha
        $campaign->refreshStats();

        // Verificar se a campanha foi totalmente processada
        $totalProcessed = $campaign->events()->whereIn('event_type', ['sent', 'failed'])->count();
        if ($totalProcessed >= $campaign->total_recipients) {
            $campaign->update([
                'status' => 'sent',
                'completed_at' => now(),
            ]);

            SystemLog::create([
                'level' => 'info',
                'source' => 'sms_campaign',
                'message' => "Campanha SMS '{$campaign->name}' concluída - {$results['sent']} enviados, {$results['failed']} falhas",
                'context' => ['campaign_id' => $campaign->id, 'results' => $results],
            ]);
        }

        return $results;
    }

    /**
     * Pausa campanha
     */
    public function pauseCampaign(SmsCampaign $campaign): bool
    {
        if (!$campaign->canPause()) return false;

        $campaign->update(['status' => 'paused']);

        SystemLog::create([
            'level' => 'info',
            'source' => 'sms_campaign',
            'message' => "Campanha SMS '{$campaign->name}' pausada",
            'context' => ['campaign_id' => $campaign->id],
        ]);

        return true;
    }

    /**
     * Cancela campanha
     */
    public function cancelCampaign(SmsCampaign $campaign): bool
    {
        if (!$campaign->canCancel()) return false;

        $campaign->update(['status' => 'cancelled']);

        SystemLog::create([
            'level' => 'info',
            'source' => 'sms_campaign',
            'message' => "Campanha SMS '{$campaign->name}' cancelada",
            'context' => ['campaign_id' => $campaign->id],
        ]);

        return true;
    }

    /**
     * Duplica campanha
     */
    public function duplicateCampaign(SmsCampaign $campaign): SmsCampaign
    {
        $new = $campaign->replicate([
            'status', 'scheduled_at', 'started_at', 'completed_at',
            'total_sent', 'total_delivered', 'total_failed', 'total_clicked',
            'sendpulse_campaign_id',
        ]);

        $new->name = $campaign->name . ' (cópia)';
        $new->status = 'draft';
        $new->total_recipients = 0;
        $new->save();

        // Copiar listas
        foreach ($campaign->lists as $list) {
            $new->lists()->attach($list->id, ['type' => $list->pivot->type]);
        }

        return $new;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignEvent;
use App\Models\EmailContact;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignEvent;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SendPulseWebhookController extends Controller
{
    /**
     * Endpoint unificado para receber TODOS os webhooks do SendPulse (Email + SMS).
     * POST /webhook/sendpulse
     *
     * O SendPulse permite configurar apenas UMA URL de webhook por evento.
     * Este endpoint detecta automaticamente se o evento é de Email ou SMS
     * e roteia para o handler correto.
     */
    public function handle(Request $request)
    {
        $data = $request->all();

        Log::info('SendPulse Webhook received', ['data' => $data]);

        try {
            // SendPulse pode enviar array de eventos ou evento único
            $events = $this->normalizeEvents($data);

            $processedEmail = 0;
            $processedSms = 0;

            foreach ($events as $event) {
                $channel = $this->detectChannel($event);

                if ($channel === 'sms') {
                    $this->processSmsEvent($event);
                    $processedSms++;
                } else {
                    $this->processEmailEvent($event);
                    $processedEmail++;
                }
            }

            return response()->json([
                'status' => 'ok',
                'processed' => [
                    'email' => $processedEmail,
                    'sms' => $processedSms,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SendPulse Webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Normaliza o payload em array de eventos.
     * SendPulse pode enviar: [{...}, {...}] ou {...} ou [chaves do evento direto]
     */
    private function normalizeEvents(array $data): array
    {
        // Se é um array indexado de eventos
        if (isset($data[0]) && is_array($data[0])) {
            return $data;
        }

        // Se tem chave 'events' contendo array
        if (isset($data['events']) && is_array($data['events'])) {
            return $data['events'];
        }

        // Evento único
        return [$data];
    }

    /**
     * Detecta se o evento é de Email ou SMS baseado no payload.
     *
     * Lógica:
     * - Se contém 'phone' ou 'recipient' (sem 'email') → SMS
     * - Se contém 'email' → Email
     * - Se contém 'sms_campaign_id' → SMS
     * - Default → Email
     */
    private function detectChannel(array $event): string
    {
        // Indicadores claros de SMS
        if (isset($event['phone']) || isset($event['recipient'])) {
            // Confirmar que não é email (alguns payloads podem ter ambos)
            if (!isset($event['email'])) {
                return 'sms';
            }
        }

        // Campo explícito do canal
        if (isset($event['channel'])) {
            return strtolower($event['channel']) === 'sms' ? 'sms' : 'email';
        }

        // Busca por campaign_id em tabela SMS
        if (isset($event['campaign_id']) && Schema::hasTable('sms_campaigns')) {
            $isSms = SmsCampaign::where('sendpulse_campaign_id', $event['campaign_id'])->exists();
            if ($isSms) return 'sms';
        }

        return 'email';
    }

    // =========================================================================
    // EMAIL EVENT PROCESSING
    // =========================================================================

    /**
     * Processa todos os tipos de evento de email do SendPulse.
     *
     * Eventos suportados (da interface SendPulse):
     * - Erro permanente (hard bounce)
     * - Erro temporário (soft bounce)
     * - Marcado como spam (complaint)
     * - Abertura de email (open)
     * - Clique no email (click)
     * - Novo assinante (subscribe)
     * - Removido da lista (unsubscribe from list)
     * - Opt-out / cancelou assinatura (unsubscribe)
     * - Estado de envio mudando (delivery status change)
     * - Entregue (delivered)
     */
    private function processEmailEvent(array $event): void
    {
        $email = $event['email'] ?? null;
        $campaignId = $event['campaign_id'] ?? $event['id'] ?? null;
        $eventType = $event['event'] ?? $event['type'] ?? $event['action'] ?? null;

        if (!$email && !$eventType) {
            Log::warning('SendPulse Email Webhook: missing email and event type', ['event' => $event]);
            return;
        }

        // Encontrar contato pelo email
        $contact = $email ? EmailContact::where('email', $email)->first() : null;

        // Encontrar campanha
        $campaign = null;
        if ($campaignId) {
            $campaign = EmailCampaign::find($campaignId);
            // Tentar também pelo sendpulse_campaign_id se existir
            if (!$campaign) {
                $campaign = EmailCampaign::where('settings->sendpulse_campaign_id', $campaignId)->first();
            }
        }

        // Mapear o tipo de evento do SendPulse para nosso sistema
        $mappedType = $this->mapEmailEventType($eventType);

        if (!$mappedType) {
            Log::info('SendPulse Email Webhook: unmapped event type', [
                'type' => $eventType,
                'email' => $email,
            ]);
            return;
        }

        // Processar cada tipo de evento
        match ($mappedType) {
            'delivered' => $this->handleEmailDelivered($campaign, $contact, $event),
            'bounced_hard' => $this->handleEmailBounce($campaign, $contact, 'hard', $event),
            'bounced_soft' => $this->handleEmailBounce($campaign, $contact, 'soft', $event),
            'opened' => $this->handleEmailOpened($campaign, $contact, $event),
            'clicked' => $this->handleEmailClicked($campaign, $contact, $event),
            'complained' => $this->handleEmailComplaint($campaign, $contact, $event),
            'unsubscribed' => $this->handleEmailUnsubscribed($campaign, $contact, $event),
            'subscribed' => $this->handleEmailSubscribed($contact, $event),
            'list_removed' => $this->handleEmailListRemoved($contact, $event),
            'status_changed' => $this->handleEmailStatusChanged($campaign, $event),
            default => null,
        };
    }

    /**
     * Mapeia nomes de eventos do SendPulse para nossos tipos internos.
     */
    private function mapEmailEventType(?string $type): ?string
    {
        if (!$type) return null;

        $type = strtolower(trim($type));

        return match ($type) {
            // Entrega
            'delivered', 'deliver', 'sent' => 'delivered',

            // Bounces
            'hard_bounce', 'permanent_error', 'hardbounce' => 'bounced_hard',
            'soft_bounce', 'temporary_error', 'softbounce' => 'bounced_soft',

            // Engajamento
            'open', 'opened', 'read' => 'opened',
            'click', 'clicked', 'redirect' => 'clicked',

            // Spam / Complaint
            'spam', 'complaint', 'complained', 'mark_as_spam', 'spam_complaint' => 'complained',

            // Assinatura
            'subscribe', 'new_subscriber', 'subscribed' => 'subscribed',
            'unsubscribe', 'unsubscribed', 'optout', 'opt_out' => 'unsubscribed',
            'list_remove', 'removed_from_list', 'list_unsubscribe' => 'list_removed',

            // Status
            'status_change', 'send_state_change', 'state_change' => 'status_changed',

            default => null,
        };
    }

    private function handleEmailDelivered(?EmailCampaign $campaign, ?EmailContact $contact, array $event): void
    {
        if (!$campaign || !$contact) return;

        // Verificar duplicata
        if ($this->emailEventExists($campaign->id, $contact->id, 'delivered')) return;

        EmailCampaignEvent::create([
            'email_campaign_id' => $campaign->id,
            'email_contact_id' => $contact->id,
            'event_type' => 'delivered',
            'occurred_at' => now(),
            'metadata' => $event,
        ]);

        $campaign->increment('total_delivered');
    }

    private function handleEmailBounce(?EmailCampaign $campaign, ?EmailContact $contact, string $bounceType, array $event): void
    {
        if (!$contact) return;

        if ($campaign) {
            if ($this->emailEventExists($campaign->id, $contact->id, 'bounced')) return;

            EmailCampaignEvent::create([
                'email_campaign_id' => $campaign->id,
                'email_contact_id' => $contact->id,
                'event_type' => 'bounced',
                'occurred_at' => now(),
                'metadata' => array_merge($event, ['bounce_type' => $bounceType]),
            ]);

            $campaign->increment('total_bounced');
        }

        // Hard bounce: marcar contato como bounced
        if ($bounceType === 'hard') {
            $contact->markBounced();
        }
    }

    private function handleEmailOpened(?EmailCampaign $campaign, ?EmailContact $contact, array $event): void
    {
        if (!$campaign || !$contact) return;

        // Para opens, permitimos múltiplos registros (total) mas controlamos unique
        EmailCampaignEvent::create([
            'email_campaign_id' => $campaign->id,
            'email_contact_id' => $contact->id,
            'event_type' => 'opened',
            'occurred_at' => now(),
            'metadata' => array_merge($event, [
                'source' => 'webhook', // distinguir do pixel tracking
                'ip' => request()->ip(),
            ]),
        ]);

        $campaign->increment('total_opened');

        // Atualizar unique opens
        $uniqueOpens = $campaign->events()
            ->where('event_type', 'opened')
            ->distinct('email_contact_id')
            ->count('email_contact_id');
        $campaign->update(['unique_opens' => $uniqueOpens]);
    }

    private function handleEmailClicked(?EmailCampaign $campaign, ?EmailContact $contact, array $event): void
    {
        if (!$campaign || !$contact) return;

        EmailCampaignEvent::create([
            'email_campaign_id' => $campaign->id,
            'email_contact_id' => $contact->id,
            'event_type' => 'clicked',
            'occurred_at' => now(),
            'metadata' => array_merge($event, [
                'source' => 'webhook',
                'url' => $event['url'] ?? $event['link'] ?? null,
            ]),
        ]);

        $campaign->increment('total_clicked');

        // Atualizar unique clicks
        $uniqueClicks = $campaign->events()
            ->where('event_type', 'clicked')
            ->distinct('email_contact_id')
            ->count('email_contact_id');
        $campaign->update(['unique_clicks' => $uniqueClicks]);
    }

    private function handleEmailComplaint(?EmailCampaign $campaign, ?EmailContact $contact, array $event): void
    {
        if (!$contact) return;

        if ($campaign) {
            if ($this->emailEventExists($campaign->id, $contact->id, 'complained')) return;

            EmailCampaignEvent::create([
                'email_campaign_id' => $campaign->id,
                'email_contact_id' => $contact->id,
                'event_type' => 'complained',
                'occurred_at' => now(),
                'metadata' => $event,
            ]);

            $campaign->increment('total_complained');
        }

        $contact->markComplained();
    }

    private function handleEmailUnsubscribed(?EmailCampaign $campaign, ?EmailContact $contact, array $event): void
    {
        if (!$contact) return;

        if ($campaign) {
            if ($this->emailEventExists($campaign->id, $contact->id, 'unsubscribed')) return;

            EmailCampaignEvent::create([
                'email_campaign_id' => $campaign->id,
                'email_contact_id' => $contact->id,
                'event_type' => 'unsubscribed',
                'occurred_at' => now(),
                'metadata' => $event,
            ]);

            $campaign->increment('total_unsubscribed');
        }

        $contact->unsubscribe();
    }

    private function handleEmailSubscribed(?EmailContact $contact, array $event): void
    {
        // Novo assinante - apenas logar, pois o contato já deve existir
        $email = $event['email'] ?? null;

        SystemLog::create([
            'level' => 'info',
            'source' => 'webhook_email',
            'message' => "Novo assinante via SendPulse: {$email}",
            'context' => $event,
        ]);

        // Se o contato existia como unsubscribed, reativar
        if ($contact && $contact->status === 'unsubscribed') {
            $contact->update([
                'status' => 'active',
                'unsubscribed_at' => null,
            ]);
        }
    }

    private function handleEmailListRemoved(?EmailContact $contact, array $event): void
    {
        $email = $event['email'] ?? null;

        SystemLog::create([
            'level' => 'info',
            'source' => 'webhook_email',
            'message' => "Contato removido de lista via SendPulse: {$email}",
            'context' => $event,
        ]);
    }

    private function handleEmailStatusChanged(?EmailCampaign $campaign, array $event): void
    {
        $newStatus = $event['status'] ?? $event['new_state'] ?? null;

        SystemLog::create([
            'level' => 'info',
            'source' => 'webhook_email',
            'message' => "Status de envio alterado via SendPulse",
            'context' => array_merge($event, [
                'campaign_id' => $campaign?->id,
                'new_status' => $newStatus,
            ]),
        ]);
    }

    /**
     * Verifica se já existe um evento de email (evita duplicatas).
     */
    private function emailEventExists(int $campaignId, int $contactId, string $eventType): bool
    {
        return EmailCampaignEvent::where('email_campaign_id', $campaignId)
            ->where('email_contact_id', $contactId)
            ->where('event_type', $eventType)
            ->exists();
    }

    // =========================================================================
    // SMS EVENT PROCESSING
    // =========================================================================

    /**
     * Processa eventos de SMS do SendPulse.
     *
     * Eventos suportados:
     * - delivered / sent → entregue
     * - undelivered / failed / rejected / expired → falha
     * - clicked → clique em link
     * - optout / SAIR → cancelamento (LGPD)
     */
    private function processSmsEvent(array $event): void
    {
        if (!Schema::hasTable('sms_campaigns')) {
            Log::warning('SMS Webhook: tabelas SMS não existem ainda');
            return;
        }

        $phone = $event['phone'] ?? $event['recipient'] ?? null;
        $status = $event['status'] ?? $event['event'] ?? $event['type'] ?? null;
        $campaignId = $event['campaign_id'] ?? $event['id'] ?? null;

        if (!$phone || !$status) {
            Log::warning('SMS Webhook: missing phone or status', ['event' => $event]);
            return;
        }

        // Verificar se é opt-out por texto (LGPD)
        $messageText = $event['text'] ?? $event['message'] ?? '';
        if (strtoupper(trim($messageText)) === 'SAIR') {
            $this->handleSmsOptOut($phone);
            return;
        }

        // Mapear status SendPulse para nosso event_type
        $eventType = match (strtolower($status)) {
            'delivered', 'sent' => 'delivered',
            'undelivered', 'failed', 'rejected', 'expired' => 'failed',
            'clicked' => 'clicked',
            'optout', 'opt_out', 'unsubscribed' => 'optout',
            default => null,
        };

        if (!$eventType) {
            Log::info('SMS Webhook: unmapped status', ['status' => $status]);
            return;
        }

        // Se for opt-out
        if ($eventType === 'optout') {
            $this->handleSmsOptOut($phone);
            return;
        }

        // Encontrar campanha
        $campaign = null;
        if ($campaignId) {
            $campaign = SmsCampaign::where('sendpulse_campaign_id', $campaignId)->first();
        }

        if (!$campaign) {
            $recentEvent = SmsCampaignEvent::where('phone', $phone)
                ->where('event_type', 'sent')
                ->orderByDesc('occurred_at')
                ->first();

            if ($recentEvent) {
                $campaign = SmsCampaign::find($recentEvent->sms_campaign_id);
            }
        }

        if (!$campaign) {
            Log::warning('SMS Webhook: campaign not found', [
                'phone' => $phone,
                'campaign_id' => $campaignId,
            ]);
            return;
        }

        // Verificar duplicata
        $exists = SmsCampaignEvent::where('sms_campaign_id', $campaign->id)
            ->where('phone', $phone)
            ->where('event_type', $eventType)
            ->exists();

        if ($exists) return;

        // Encontrar contato
        $contact = EmailContact::where('phone', 'like', "%{$phone}%")->first();

        SmsCampaignEvent::create([
            'sms_campaign_id' => $campaign->id,
            'email_contact_id' => $contact?->id,
            'phone' => $phone,
            'event_type' => $eventType,
            'metadata' => $event,
            'occurred_at' => now(),
        ]);

        // Atualizar stats da campanha
        $campaign->refreshStats();
    }

    /**
     * Processa opt-out SMS (LGPD).
     */
    private function handleSmsOptOut(string $phone): void
    {
        $contacts = EmailContact::where('phone', 'like', "%{$phone}%")->get();

        foreach ($contacts as $contact) {
            $metadata = $contact->metadata ?? [];
            $metadata['sms_optout'] = true;
            $metadata['sms_optout_at'] = now()->toISOString();

            $contact->update(['metadata' => $metadata]);
        }

        SystemLog::create([
            'level' => 'info',
            'source' => 'sms_optout',
            'message' => "SMS Opt-out recebido via webhook: {$phone}",
            'context' => ['phone' => $phone],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\EmailContact;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignEvent;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SmsWebhookController extends Controller
{
    /**
     * Webhook para receber status de entrega do SendPulse SMS
     * POST /sms/webhook/sendpulse
     */
    public function sendpulseWebhook(Request $request)
    {
        $data = $request->all();

        Log::info('SMS Webhook received', ['data' => $data]);

        try {
            // SendPulse envia array de eventos
            $events = is_array($data) && isset($data[0]) ? $data : [$data];

            foreach ($events as $event) {
                $this->processEvent($event);
            }

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            Log::error('SMS Webhook error', ['error' => $e->getMessage(), 'data' => $data]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function processEvent(array $event): void
    {
        $phone = $event['phone'] ?? $event['recipient'] ?? null;
        $status = $event['status'] ?? $event['event'] ?? null;
        $campaignId = $event['campaign_id'] ?? $event['id'] ?? null;

        if (!$phone || !$status) {
            return;
        }

        // Mapear status SendPulse para nosso event_type
        $eventType = match (strtolower($status)) {
            'delivered', 'sent' => 'delivered',
            'undelivered', 'failed', 'rejected', 'expired' => 'failed',
            'clicked' => 'clicked',
            default => null,
        };

        if (!$eventType) return;

        // Encontrar a campanha pelo sendpulse_campaign_id ou pelo phone
        $campaign = null;
        if ($campaignId) {
            $campaign = SmsCampaign::where('sendpulse_campaign_id', $campaignId)->first();
        }

        // Se não encontrou pelo campaign_id, buscar pela phone nos eventos mais recentes
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
            Log::warning('SMS Webhook: campaign not found', ['phone' => $phone, 'campaign_id' => $campaignId]);
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

        // Se for opt-out, marcar contato
        if ($eventType === 'optout' || ($event['text'] ?? '') === 'SAIR') {
            $this->handleOptOut($phone);
        }
    }

    /**
     * Processa opt-out SMS (LGPD)
     */
    private function handleOptOut(string $phone): void
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
            'message' => "SMS Opt-out recebido: {$phone}",
            'context' => ['phone' => $phone],
        ]);
    }
}

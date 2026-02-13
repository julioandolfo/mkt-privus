<?php

namespace App\Services\Email;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignEvent;
use App\Models\EmailContact;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Crypt;

class EmailTrackingService
{
    /**
     * Gera pixel de rastreamento de abertura
     */
    public function generateTrackingPixel(int $campaignId, int $contactId): string
    {
        $token = $this->encodeToken($campaignId, $contactId);
        $url = route('email.track.open', ['token' => $token]);
        return '<img src="' . $url . '" width="1" height="1" style="display:none;" alt="" />';
    }

    /**
     * Gera URL de unsubscribe
     */
    public function generateUnsubscribeUrl(int $campaignId, int $contactId): string
    {
        $token = $this->encodeToken($campaignId, $contactId);
        return route('email.unsubscribe', ['token' => $token]);
    }

    /**
     * Wrap links para tracking de cliques
     */
    public function wrapLinks(string $html, int $campaignId, int $contactId): string
    {
        return preg_replace_callback(
            '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/i',
            function ($matches) use ($campaignId, $contactId) {
                $originalUrl = $matches[2];

                // Nao trackear links especiais
                if (str_contains($originalUrl, 'unsubscribe') ||
                    str_contains($originalUrl, 'mailto:') ||
                    str_contains($originalUrl, '#')) {
                    return $matches[0];
                }

                $token = $this->encodeToken($campaignId, $contactId, $originalUrl);
                $trackUrl = route('email.track.click', ['token' => $token]);

                return '<a ' . $matches[1] . 'href="' . $trackUrl . '"' . $matches[3] . '>';
            },
            $html
        );
    }

    /**
     * Processa evento de abertura
     */
    public function processOpen(string $token): void
    {
        $data = $this->decodeToken($token);
        if (!$data) return;

        EmailCampaignEvent::create([
            'email_campaign_id' => $data['campaign_id'],
            'email_contact_id' => $data['contact_id'],
            'event_type' => 'opened',
            'occurred_at' => now(),
            'metadata' => [
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
        ]);

        // Atualizar estatísticas cache
        $campaign = EmailCampaign::find($data['campaign_id']);
        if ($campaign) {
            $campaign->increment('total_opened');
            $uniqueOpens = $campaign->events()
                ->where('event_type', 'opened')
                ->distinct('email_contact_id')
                ->count('email_contact_id');
            $campaign->update(['unique_opens' => $uniqueOpens]);
        }
    }

    /**
     * Processa evento de clique
     */
    public function processClick(string $token): ?string
    {
        $data = $this->decodeToken($token);
        if (!$data) return null;

        $url = $data['url'] ?? null;

        EmailCampaignEvent::create([
            'email_campaign_id' => $data['campaign_id'],
            'email_contact_id' => $data['contact_id'],
            'event_type' => 'clicked',
            'occurred_at' => now(),
            'metadata' => [
                'url' => $url,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
        ]);

        // Atualizar estatísticas cache
        $campaign = EmailCampaign::find($data['campaign_id']);
        if ($campaign) {
            $campaign->increment('total_clicked');
            $uniqueClicks = $campaign->events()
                ->where('event_type', 'clicked')
                ->distinct('email_contact_id')
                ->count('email_contact_id');
            $campaign->update(['unique_clicks' => $uniqueClicks]);
        }

        return $url;
    }

    /**
     * Processa unsubscribe
     */
    public function processUnsubscribe(string $token): bool
    {
        $data = $this->decodeToken($token);
        if (!$data) return false;

        EmailCampaignEvent::create([
            'email_campaign_id' => $data['campaign_id'],
            'email_contact_id' => $data['contact_id'],
            'event_type' => 'unsubscribed',
            'occurred_at' => now(),
        ]);

        $contact = EmailContact::find($data['contact_id']);
        if ($contact) {
            $contact->unsubscribe();
        }

        $campaign = EmailCampaign::find($data['campaign_id']);
        if ($campaign) {
            $campaign->increment('total_unsubscribed');
        }

        return true;
    }

    /**
     * Processa bounce (via webhook)
     */
    public function processBounce(int $campaignId, string $email, string $type = 'hard'): void
    {
        $contact = EmailContact::where('email', $email)->first();
        if (!$contact) return;

        EmailCampaignEvent::create([
            'email_campaign_id' => $campaignId,
            'email_contact_id' => $contact->id,
            'event_type' => 'bounced',
            'occurred_at' => now(),
            'metadata' => ['bounce_type' => $type],
        ]);

        if ($type === 'hard') {
            $contact->markBounced();
        }

        $campaign = EmailCampaign::find($campaignId);
        if ($campaign) {
            $campaign->increment('total_bounced');
        }
    }

    /**
     * Processa complaint (via webhook)
     */
    public function processComplaint(int $campaignId, string $email): void
    {
        $contact = EmailContact::where('email', $email)->first();
        if (!$contact) return;

        EmailCampaignEvent::create([
            'email_campaign_id' => $campaignId,
            'email_contact_id' => $contact->id,
            'event_type' => 'complained',
            'occurred_at' => now(),
        ]);

        $contact->markComplained();

        $campaign = EmailCampaign::find($campaignId);
        if ($campaign) {
            $campaign->increment('total_complained');
        }
    }

    // ===== HELPERS =====

    private function encodeToken(int $campaignId, int $contactId, ?string $url = null): string
    {
        $data = [
            'c' => $campaignId,
            'ct' => $contactId,
        ];

        if ($url) {
            $data['u'] = $url;
        }

        return Crypt::encryptString(json_encode($data));
    }

    private function decodeToken(string $token): ?array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($token), true);
            if (!$decoded || !isset($decoded['c'], $decoded['ct'])) return null;

            return [
                'campaign_id' => $decoded['c'],
                'contact_id' => $decoded['ct'],
                'url' => $decoded['u'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}

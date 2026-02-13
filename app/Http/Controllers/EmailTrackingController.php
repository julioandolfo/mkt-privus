<?php

namespace App\Http\Controllers;

use App\Services\Email\EmailTrackingService;
use Illuminate\Http\Request;

class EmailTrackingController extends Controller
{
    public function __construct(
        private EmailTrackingService $trackingService,
    ) {}

    /**
     * Pixel de abertura (1x1 gif transparente)
     */
    public function open(string $token)
    {
        $this->trackingService->processOpen($token);

        // Retornar 1x1 GIF transparente
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        return response($pixel, 200)
            ->header('Content-Type', 'image/gif')
            ->header('Content-Length', strlen($pixel))
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Redirect de clique com tracking
     */
    public function click(string $token)
    {
        $url = $this->trackingService->processClick($token);

        if ($url) {
            return redirect()->away($url);
        }

        return redirect('/');
    }

    /**
     * Pagina de unsubscribe
     */
    public function unsubscribe(string $token)
    {
        $success = $this->trackingService->processUnsubscribe($token);

        return response()->view('emails.unsubscribe', [
            'success' => $success,
        ]);
    }

    /**
     * Webhook do SendPulse
     */
    public function sendpulseWebhook(Request $request)
    {
        $events = $request->all();

        foreach ((array) $events as $event) {
            $campaignId = $event['campaign_id'] ?? null;
            $email = $event['email'] ?? null;

            if (!$campaignId || !$email) continue;

            $type = $event['event'] ?? $event['type'] ?? null;

            match ($type) {
                'hard_bounce', 'soft_bounce' => $this->trackingService->processBounce(
                    $campaignId,
                    $email,
                    str_contains($type, 'hard') ? 'hard' : 'soft'
                ),
                'spam', 'complaint' => $this->trackingService->processComplaint($campaignId, $email),
                default => null,
            };
        }

        return response()->json(['status' => 'ok']);
    }
}

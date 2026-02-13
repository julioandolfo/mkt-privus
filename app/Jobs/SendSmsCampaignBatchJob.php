<?php

namespace App\Jobs;

use App\Models\SmsCampaign;
use App\Services\Sms\SmsCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSmsCampaignBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutos por lote
    public int $backoff = 30;

    public function __construct(
        public int $campaignId,
        public array $contactIds,
        public int $batchIndex = 0,
    ) {}

    public function handle(SmsCampaignService $service): void
    {
        $campaign = SmsCampaign::find($this->campaignId);

        if (!$campaign || !in_array($campaign->status, ['sending'])) {
            Log::info("SMS batch skipped - campaign {$this->campaignId} status: " . ($campaign->status ?? 'not found'));
            return;
        }

        Log::info("SMS batch {$this->batchIndex} started for campaign {$this->campaignId}", [
            'contacts' => count($this->contactIds),
        ]);

        $results = $service->sendBatch($campaign, $this->contactIds);

        Log::info("SMS batch {$this->batchIndex} completed for campaign {$this->campaignId}", $results);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SMS batch {$this->batchIndex} FAILED for campaign {$this->campaignId}", [
            'error' => $exception->getMessage(),
        ]);
    }
}

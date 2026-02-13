<?php

namespace App\Jobs;

use App\Services\Email\EmailCampaignService;
use App\Models\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCampaignBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public int $backoff = 60;

    public function __construct(
        public int $campaignId,
        public array $contactIds,
    ) {}

    public function handle(EmailCampaignService $service): void
    {
        try {
            $result = $service->processBatch($this->campaignId, $this->contactIds);

            SystemLog::info('email', 'batch.processed', "Batch processado: {$result['sent']} enviados, {$result['failed']} falhas", [
                'campaign_id' => $this->campaignId,
                'sent' => $result['sent'],
                'failed' => $result['failed'],
                'batch_size' => count($this->contactIds),
            ]);
        } catch (\Throwable $e) {
            Log::error("SendCampaignBatchJob failed", [
                'campaign_id' => $this->campaignId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        SystemLog::error('email', 'batch.failed', "Batch falhou: {$exception->getMessage()}", [
            'campaign_id' => $this->campaignId,
            'contacts' => count($this->contactIds),
        ]);
    }
}

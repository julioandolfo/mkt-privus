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
        SystemLog::info('email', 'batch.job.started', "Job de batch iniciado", [
            'campaign_id' => $this->campaignId,
            'batch_size' => count($this->contactIds),
            'contact_ids' => $this->contactIds,
            'job_id' => $this->job->uuid() ?? null,
            'attempt' => $this->attempts(),
        ]);

        try {
            $result = $service->processBatch($this->campaignId, $this->contactIds);

            SystemLog::info('email', 'batch.job.completed', "Job de batch finalizado", [
                'campaign_id' => $this->campaignId,
                'sent' => $result['sent'],
                'failed' => $result['failed'],
                'reason' => $result['reason'] ?? null,
                'batch_size' => count($this->contactIds),
            ]);
        } catch (\Throwable $e) {
            SystemLog::error('email', 'batch.job.exception', "Exceção no job de batch: {$e->getMessage()}", [
                'campaign_id' => $this->campaignId,
                'batch_size' => count($this->contactIds),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        SystemLog::error('email', 'batch.job.failed', "Job falhou definitivamente após {$this->tries} tentativas: {$exception->getMessage()}", [
            'campaign_id' => $this->campaignId,
            'contacts' => count($this->contactIds),
            'contact_ids' => $this->contactIds,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Marcar todos os contatos do batch como falha
        foreach ($this->contactIds as $contactId) {
            \App\Models\EmailCampaignEvent::create([
                'email_campaign_id' => $this->campaignId,
                'email_contact_id' => $contactId,
                'event_type' => 'failed',
                'occurred_at' => now(),
                'metadata' => ['error' => 'Job failed after ' . $this->tries . ' attempts: ' . $exception->getMessage()],
            ]);
        }
    }
}

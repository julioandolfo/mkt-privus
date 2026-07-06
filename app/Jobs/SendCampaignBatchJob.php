<?php

namespace App\Jobs;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignEvent;
use App\Models\SystemLog;
use App\Services\Email\EmailCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendCampaignBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Abaixo do --timeout=290 do worker e do retry_after=320 da fila, para que
    // o próprio worker não mate o job no meio de um batch e o reprocesse.
    public $timeout = 280;
    public $tries = 3;

    // Propriedades precisam ser public para serialização correta na fila
    public int $campaignId;
    public array $contactIds;

    public function __construct(int $campaignId, array $contactIds)
    {
        $this->campaignId = $campaignId;
        $this->contactIds = $contactIds;
    }

    public function handle(EmailCampaignService $service): void
    {
        SystemLog::info('email', 'batch.job.started', "Job iniciado para processar batch da campanha", [
            'campaign_id' => $this->campaignId,
            'batch_size' => count($this->contactIds),
            'contact_ids_sample' => array_slice($this->contactIds, 0, 5),
        ]);

        try {
            $result = $service->processBatch($this->campaignId, $this->contactIds);

            SystemLog::info('email', 'batch.job.completed', "Job finalizado com sucesso", [
                'campaign_id' => $this->campaignId,
                'sent' => $result['sent'],
                'failed' => $result['failed'],
                'reason' => $result['reason'] ?? null,
            ]);
        } catch (Throwable $e) {
            SystemLog::error('email', 'batch.job.exception', "Exceção não tratada no job: {$e->getMessage()}", [
                'campaign_id' => $this->campaignId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        SystemLog::error('email', 'batch.job.failed', "Job falhou permanentemente após todas as tentativas", [
            'campaign_id' => $this->campaignId,
            'batch_size' => count($this->contactIds),
            'exception' => $exception->getMessage(),
        ]);

        // Marcar como falhos APENAS os contatos que ainda não foram enviados nem
        // já registrados como falha. Marcar todos indiscriminadamente criava
        // eventos 'failed' para contatos que já tinham 'sent' neste mesmo batch,
        // corrompendo as estatísticas (delivery/bounce/failed inflados).
        try {
            $now = now();

            $alreadyProcessed = EmailCampaignEvent::where('email_campaign_id', $this->campaignId)
                ->whereIn('email_contact_id', $this->contactIds)
                ->whereIn('event_type', ['sent', 'failed'])
                ->pluck('email_contact_id')
                ->unique()
                ->all();

            $toFail = array_values(array_diff($this->contactIds, $alreadyProcessed));

            $events = collect($toFail)->map(fn($contactId) => [
                'email_campaign_id' => $this->campaignId,
                'email_contact_id' => $contactId,
                'event_type' => 'failed',
                'occurred_at' => $now,
                'metadata' => json_encode(['error' => 'Job failed after all retries: ' . $exception->getMessage()]),
                'created_at' => $now,
                'updated_at' => $now,
            ])->toArray();

            foreach (array_chunk($events, 500) as $chunk) {
                EmailCampaignEvent::insert($chunk);
            }

            SystemLog::info('email', 'batch.job.marked_failed', "Contatos pendentes do batch marcados como failed após falha do job", [
                'campaign_id' => $this->campaignId,
                'contacts_failed' => count($toFail),
                'contacts_skipped_already_processed' => count($alreadyProcessed),
            ]);
        } catch (Throwable $e) {
            SystemLog::error('email', 'batch.job.mark_failed_error', "Erro ao marcar contatos como failed: {$e->getMessage()}", [
                'campaign_id' => $this->campaignId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

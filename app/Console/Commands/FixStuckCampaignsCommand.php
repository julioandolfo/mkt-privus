<?php

namespace App\Console\Commands;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignEvent;
use App\Models\SystemLog;
use App\Services\Email\EmailCampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Detecta e corrige campanhas travadas em status 'sending'
 */
class FixStuckCampaignsCommand extends Command
{
    protected $signature = 'email:fix-stuck
                            {--campaign= : ID de uma campanha específica}
                            {--hours=2 : Horas sem atividade para considerar travada}
                            {--dry-run : Apenas mostrar, sem corrigir}
                            {--force-complete : Marcar campanha como concluída mesmo com pendentes}
                            {--reset-to-draft : Resetar para rascunho para reenviar}';

    protected $description = 'Detecta e corrige campanhas travadas em status sending';

    public function handle(): int
    {
        $hours   = (int) $this->option('hours');
        $dryRun  = $this->option('dry-run');
        $campaignId = $this->option('campaign');

        $this->info("=== DETECTOR DE CAMPANHAS TRAVADAS ===\n");

        if ($dryRun) {
            $this->warn("MODO SIMULAÇÃO — nenhuma alteração será feita\n");
        }

        // Buscar campanhas stuck
        $query = EmailCampaign::where('status', 'sending');

        if ($campaignId) {
            $query->where('id', $campaignId);
        } else {
            $query->where('started_at', '<', now()->subHours($hours));
        }

        $campaigns = $query->get();

        if ($campaigns->isEmpty()) {
            $this->info("✅ Nenhuma campanha travada encontrada.");
            return 0;
        }

        $this->warn("Encontradas {$campaigns->count()} campanha(s) em status 'sending':\n");

        foreach ($campaigns as $campaign) {
            $this->processCampaign($campaign, $dryRun);
            $this->newLine();
        }

        return 0;
    }

    private function processCampaign(EmailCampaign $campaign, bool $dryRun): void
    {
        $horasSendando = $campaign->started_at
            ? now()->diffInHours($campaign->started_at)
            : 0;

        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("Campanha: {$campaign->name} (ID: {$campaign->id})");
        $this->line("Iniciada há: {$horasSendando}h");
        $this->line("Destinatários: {$campaign->total_recipients}");

        // Contar eventos
        $queued    = $campaign->events()->where('event_type', 'queued')->distinct('email_contact_id')->count('email_contact_id');
        $sent      = $campaign->events()->where('event_type', 'sent')->distinct('email_contact_id')->count('email_contact_id');
        $failed    = $campaign->events()->where('event_type', 'failed')->distinct('email_contact_id')->count('email_contact_id');
        $processed = $sent + $failed;
        $remaining = max(0, $queued - $processed);

        $this->line("Queued: {$queued} | Sent: {$sent} | Failed: {$failed} | Restantes: {$remaining}");

        // Último evento da campanha (a fila roda em Redis, então não dá para
        // contar jobs via DB::table('jobs') — usamos a inatividade como sinal).
        $ultimoEvento = $campaign->events()->latest('occurred_at')->first();
        $minDesdeUltimo = $ultimoEvento
            ? now()->diffInMinutes($ultimoEvento->occurred_at, true)
            : null;

        if ($minDesdeUltimo !== null) {
            $this->line("Último evento: há " . round($minDesdeUltimo) . " minutos ({$ultimoEvento->event_type})");
        }

        // Determinar o que fazer
        if ($remaining === 0 && $processed >= $queued) {
            $this->warn("→ Todos processados mas campanha não foi marcada como concluída!");
            if (!$dryRun) {
                $this->completeCampaign($campaign);
            }
            return;
        }

        // Atividade recente (< 15 min) → provavelmente ainda processando na fila.
        if ($minDesdeUltimo !== null && $minDesdeUltimo < 15) {
            $this->info("→ Houve atividade há " . round($minDesdeUltimo) . " min; a campanha provavelmente ainda está processando.");
            return;
        }

        // Sem atividade recente e ainda há pendentes → provavelmente travada
        $this->error("→ Possivelmente TRAVADA! {$remaining} emails pendentes e sem atividade recente.");

        if ($this->option('force-complete')) {
            if (!$dryRun) {
                $this->completeCampaign($campaign);
                $this->info("✅ Marcada como concluída (forçado).");
            } else {
                $this->line("  [simulação] Seria marcada como concluída.");
            }
        } elseif ($this->option('reset-to-draft')) {
            if (!$dryRun) {
                $this->resetToDraft($campaign);
                $this->info("✅ Resetada para draft. Reenvie manualmente.");
            } else {
                $this->line("  [simulação] Seria resetada para draft.");
            }
        } else {
            $this->line("  Use --force-complete para marcar como concluída");
            $this->line("  Use --reset-to-draft para resetar e reenviar");
        }
    }

    private function completeCampaign(EmailCampaign $campaign): void
    {
        $campaign->update([
            'status'       => 'sent',
            'completed_at' => now(),
        ]);
        $campaign->refreshStats();

        SystemLog::info('email', 'campaign.force_completed', "Campanha marcada como concluída manualmente", [
            'campaign_id' => $campaign->id,
            'reason'      => 'stuck_campaign_fix',
        ]);
    }

    private function resetToDraft(EmailCampaign $campaign): void
    {
        // Remove apenas eventos failed, mantém sent
        EmailCampaignEvent::where('email_campaign_id', $campaign->id)
            ->whereIn('event_type', ['failed', 'queued'])
            ->delete();

        $campaign->update([
            'status'       => 'draft',
            'started_at'   => null,
            'completed_at' => null,
            'total_sent'   => 0,
        ]);

        SystemLog::info('email', 'campaign.reset_to_draft', "Campanha resetada para draft manualmente", [
            'campaign_id' => $campaign->id,
            'reason'      => 'stuck_campaign_fix',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use App\Models\EmailList;
use App\Models\EmailProvider;
use App\Models\EmailTemplate;
use App\Models\SystemLog;
use App\Services\Email\EmailCampaignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class EmailCampaignController extends Controller
{
    public function __construct(
        private EmailCampaignService $campaignService,
    ) {}

    public function index(Request $request)
    {
        $brandId = session('current_brand_id');

        $campaigns = EmailCampaign::forBrand($brandId)
            ->with('provider:id,name,type')
            ->latest()
            ->paginate(20)
            ->through(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'subject' => $c->subject,
                'status' => $c->status,
                'type' => $c->type,
                'provider' => $c->provider ? ['name' => $c->provider->name, 'type' => $c->provider->type] : null,
                'total_recipients' => $c->total_recipients,
                'total_sent' => $c->total_sent,
                'total_delivered' => $c->total_delivered,
                'total_opened' => $c->total_opened,
                'total_clicked' => $c->total_clicked,
                'open_rate' => $c->open_rate,
                'click_rate' => $c->click_rate,
                'scheduled_at' => $c->scheduled_at?->format('d/m/Y H:i'),
                'started_at' => $c->started_at?->format('d/m/Y H:i'),
                'completed_at' => $c->completed_at?->format('d/m/Y H:i'),
                'created_at' => $c->created_at->format('d/m/Y'),
            ]);

        return Inertia::render('Email/Campaigns/Index', [
            'campaigns' => $campaigns,
        ]);
    }

    public function create(Request $request)
    {
        $brandId = session('current_brand_id');

        $providers = EmailProvider::active()
            ->forBrand($brandId)
            ->get(['id', 'name', 'type', 'is_default', 'hourly_limit', 'sends_this_hour', 'daily_limit', 'sends_today', 'last_hour_reset_at', 'last_reset_at'])
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'type' => $p->type,
                    'is_default' => $p->is_default,
                    'quota_info' => $p->getQuotaInfo(),
                ];
            });

        $lists = EmailList::active()
            ->forBrand($brandId)
            ->withCount('contacts')
            ->get(['id', 'name']);

        $templates = EmailTemplate::forBrand($brandId)
            ->active()
            ->get(['id', 'name', 'subject', 'category', 'thumbnail_path', 'html_content']);

        return Inertia::render('Email/Campaigns/Create', [
            'providers' => $providers,
            'lists' => $lists,
            'templates' => $templates,
            'starterTemplates' => EmailTemplateController::getStarterTemplates(),
        ]);
    }

    public function store(Request $request)
    {
        $isDraft = $request->input('status') === 'draft';

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'nullable|string|max:255',
            'preview_text' => 'nullable|string|max:255',
            'from_name' => 'nullable|string|max:255',
            'from_email' => 'nullable|email',
            'reply_to' => 'nullable|email',
            'email_provider_id' => ($isDraft ? 'nullable' : 'required') . '|exists:email_providers,id',
            'email_template_id' => 'nullable|exists:email_templates,id',
            'html_content' => 'nullable|string',
            'mjml_content' => 'nullable|string',
            'json_content' => 'nullable|array',
            'type' => 'nullable|in:regular,ab_test',
            'lists' => $isDraft ? 'nullable|array' : 'required|array|min:1',
            'lists.*' => 'exists:email_lists,id',
            'exclude_lists' => 'nullable|array',
            'exclude_lists.*' => 'exists:email_lists,id',
            'settings' => 'nullable|array',
            'tags' => 'nullable|array',
            'status' => 'nullable|in:draft,scheduled',
        ]);

        $campaign = EmailCampaign::create([
            'brand_id' => session('current_brand_id'),
            'user_id' => Auth::id(),
            'email_provider_id' => $validated['email_provider_id'] ?? null,
            'email_template_id' => $validated['email_template_id'] ?? null,
            'name' => $validated['name'],
            'subject' => $validated['subject'] ?? '',
            'preview_text' => $validated['preview_text'] ?? null,
            'from_name' => $validated['from_name'] ?? null,
            'from_email' => $validated['from_email'] ?? null,
            'reply_to' => $validated['reply_to'] ?? null,
            'html_content' => $validated['html_content'] ?? null,
            'mjml_content' => $validated['mjml_content'] ?? null,
            'json_content' => $validated['json_content'] ?? null,
            'type' => $validated['type'] ?? 'regular',
            'status' => $validated['status'] ?? 'draft',
            'tags' => $validated['tags'] ?? null,
            'settings' => array_merge([
                'track_opens' => true,
                'track_clicks' => true,
                'send_speed' => 100,
            ], $validated['settings'] ?? []),
        ]);

        // Vincular listas
        foreach ($validated['lists'] as $listId) {
            $campaign->lists()->attach($listId, ['type' => 'include']);
        }
        foreach ($validated['exclude_lists'] ?? [] as $listId) {
            $campaign->lists()->attach($listId, ['type' => 'exclude']);
        }

        // Calcular total (apenas se não for rascunho sem listas)
        if (!empty($validated['lists'])) {
            $this->campaignService->prepareCampaign($campaign);
        }

        $message = $isDraft ? 'Rascunho salvo com sucesso!' : 'Campanha criada com sucesso!';

        return redirect()->route('email.campaigns.show', $campaign)
            ->with('success', $message);
    }

    public function show(EmailCampaign $campaign)
    {
        $campaign->load(['provider:id,name,type,hourly_limit,sends_this_hour', 'lists:id,name', 'template:id,name']);

        // Buscar ultimos eventos
        $recentEvents = $campaign->events()
            ->with('contact:id,email,first_name,last_name')
            ->latest('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'contact' => $e->contact ? ['email' => $e->contact->email, 'name' => $e->contact->full_name] : null,
                'metadata' => $e->metadata,
                'occurred_at' => $e->occurred_at->format('d/m/Y H:i:s'),
            ]);

        // Stats por hora (ultimas 48h) se campanha enviada
        $hourlyStats = [];
        if (in_array($campaign->status, ['sending', 'sent'])) {
            $hours = $campaign->events()
                ->where('occurred_at', '>=', now()->subHours(48))
                ->selectRaw("DATE_FORMAT(occurred_at, '%Y-%m-%d %H:00') as hour, event_type, COUNT(*) as count")
                ->groupBy('hour', 'event_type')
                ->orderBy('hour')
                ->get();

            foreach ($hours as $row) {
                $hourlyStats[$row->hour][$row->event_type] = $row->count;
            }
        }

        // Informações de agendamento/progresso
        $scheduleInfo = null;
        $hourlyLimit = $campaign->provider?->hourly_limit;

        if ($campaign->isScheduled() && $campaign->scheduled_at) {
            $scheduleInfo = [
                'type' => 'scheduled',
                'scheduled_at' => $campaign->scheduled_at->toISOString(),
                'scheduled_at_formatted' => $campaign->scheduled_at->format('d/m/Y H:i:s'),
                'time_until' => $campaign->scheduled_at->diffForHumans(),
                'is_overdue' => $campaign->scheduled_at->isPast(),
                'hourly_limit' => $hourlyLimit,
            ];
        } elseif ($campaign->isSending()) {
            // Calcular progresso e ETA
            // Usar distinct para evitar contagens duplicadas se houver múltiplos eventos por contato
            $totalQueued = $campaign->events()
                ->where('event_type', 'queued')
                ->distinct('email_contact_id')
                ->count('email_contact_id');

            $totalProcessed = $campaign->events()
                ->whereIn('event_type', ['sent', 'failed'])
                ->distinct('email_contact_id')
                ->count('email_contact_id');

            $remaining = max(0, $totalQueued - $totalProcessed);

            // Velocidade de envio (padrão: 100 emails/minuto = 1 batch/min)
            $sendSpeed = $campaign->getSetting('send_speed', 100);

            // Se tem limite por hora, calcular ETA em horas
            if ($hourlyLimit && $hourlyLimit > 0) {
                $etaHours = ceil($remaining / $hourlyLimit);
                $etaMinutes = $etaHours * 60;
                $etaFormatted = $etaHours . ' horas (limite: ' . $hourlyLimit . '/hora)';
            } else {
                $etaMinutes = ceil($remaining / $sendSpeed);
                $etaFormatted = $etaMinutes > 60
                    ? ceil($etaMinutes / 60) . ' horas'
                    : $etaMinutes . ' minutos';
            }

            $scheduleInfo = [
                'type' => 'sending',
                'progress_percent' => $totalQueued > 0 ? round(($totalProcessed / $totalQueued) * 100, 1) : 0,
                'total_queued' => $totalQueued,
                'total_processed' => $totalProcessed,
                'remaining' => $remaining,
                'eta_minutes' => $etaMinutes,
                'eta_formatted' => $etaFormatted,
                'send_speed' => $sendSpeed,
                'hourly_limit' => $hourlyLimit,
            ];
        }

        return Inertia::render('Email/Campaigns/Show', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'subject' => $campaign->subject,
                'preview_text' => $campaign->preview_text,
                'from_name' => $campaign->from_name,
                'from_email' => $campaign->from_email,
                'reply_to' => $campaign->reply_to,
                'status' => $campaign->status,
                'type' => $campaign->type,
                'html_content' => $campaign->html_content,
                'provider' => $campaign->provider ? ['name' => $campaign->provider->name, 'type' => $campaign->provider->type] : null,
                'template' => $campaign->template ? ['name' => $campaign->template->name] : null,
                'lists' => $campaign->lists->map(fn($l) => ['id' => $l->id, 'name' => $l->name, 'type' => $l->pivot->type]),
                'total_recipients' => $campaign->total_recipients,
                'total_sent' => $campaign->total_sent,
                'total_delivered' => $campaign->total_delivered,
                'total_bounced' => $campaign->total_bounced,
                'total_opened' => $campaign->total_opened,
                'total_clicked' => $campaign->total_clicked,
                'total_unsubscribed' => $campaign->total_unsubscribed,
                'total_complained' => $campaign->total_complained,
                'unique_opens' => $campaign->unique_opens,
                'unique_clicks' => $campaign->unique_clicks,
                'open_rate' => $campaign->open_rate,
                'click_rate' => $campaign->click_rate,
                'bounce_rate' => $campaign->bounce_rate,
                'delivery_rate' => $campaign->delivery_rate,
                'unsubscribe_rate' => $campaign->unsubscribe_rate,
                'scheduled_at' => $campaign->scheduled_at?->format('d/m/Y H:i'),
                'started_at' => $campaign->started_at?->format('d/m/Y H:i'),
                'completed_at' => $campaign->completed_at?->format('d/m/Y H:i'),
                'can_edit' => $campaign->canEdit(),
                'can_send' => $campaign->canSend(),
                'can_pause' => $campaign->canPause(),
                'can_cancel' => $campaign->canCancel(),
                'settings' => $campaign->settings,
                'tags' => $campaign->tags,
                'created_at' => $campaign->created_at->format('d/m/Y H:i'),
            ],
            'recentEvents' => $recentEvents,
            'hourlyStats' => $hourlyStats,
            'scheduleInfo' => $scheduleInfo,
        ]);
    }

    public function edit(EmailCampaign $campaign)
    {
        if (!$campaign->canEdit()) {
            return back()->with('error', 'Esta campanha não pode ser editada.');
        }

        $brandId = session('current_brand_id');

        $providers = EmailProvider::active()
            ->forBrand($brandId)
            ->get(['id', 'name', 'type', 'is_default', 'hourly_limit', 'sends_this_hour', 'daily_limit', 'sends_today', 'last_hour_reset_at', 'last_reset_at'])
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'type' => $p->type,
                    'is_default' => $p->is_default,
                    'quota_info' => $p->getQuotaInfo(),
                ];
            });

        return Inertia::render('Email/Campaigns/Edit', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'subject' => $campaign->subject,
                'preview_text' => $campaign->preview_text,
                'from_name' => $campaign->from_name,
                'from_email' => $campaign->from_email,
                'reply_to' => $campaign->reply_to,
                'email_provider_id' => $campaign->email_provider_id,
                'email_template_id' => $campaign->email_template_id,
                'html_content' => $campaign->html_content,
                'mjml_content' => $campaign->mjml_content,
                'json_content' => $campaign->json_content,
                'type' => $campaign->type,
                'tags' => $campaign->tags,
                'settings' => $campaign->settings,
                'lists' => $campaign->includeLists()->pluck('email_lists.id'),
                'exclude_lists' => $campaign->excludeLists()->pluck('email_lists.id'),
            ],
            'providers' => $providers,
            'lists' => EmailList::active()->forBrand($brandId)->withCount('contacts')->get(['id', 'name']),
            'templates' => EmailTemplate::forBrand($brandId)->active()->get(['id', 'name', 'subject', 'category']),
        ]);
    }

    public function update(Request $request, EmailCampaign $campaign)
    {
        if (!$campaign->canEdit()) {
            return back()->with('error', 'Esta campanha não pode ser editada.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'preview_text' => 'nullable|string|max:255',
            'from_name' => 'nullable|string|max:255',
            'from_email' => 'nullable|email',
            'reply_to' => 'nullable|email',
            'email_provider_id' => 'required|exists:email_providers,id',
            'html_content' => 'nullable|string',
            'mjml_content' => 'nullable|string',
            'json_content' => 'nullable|array',
            'lists' => 'required|array|min:1',
            'exclude_lists' => 'nullable|array',
            'settings' => 'nullable|array',
            'tags' => 'nullable|array',
        ]);

        $campaign->update([
            'name' => $validated['name'],
            'subject' => $validated['subject'],
            'preview_text' => $validated['preview_text'] ?? null,
            'from_name' => $validated['from_name'],
            'from_email' => $validated['from_email'],
            'reply_to' => $validated['reply_to'] ?? null,
            'email_provider_id' => $validated['email_provider_id'],
            'html_content' => $validated['html_content'] ?? null,
            'mjml_content' => $validated['mjml_content'] ?? null,
            'json_content' => $validated['json_content'] ?? null,
            'settings' => $validated['settings'] ?? $campaign->settings,
            'tags' => $validated['tags'] ?? null,
        ]);

        // Atualizar listas
        $campaign->lists()->detach();
        foreach ($validated['lists'] as $listId) {
            $campaign->lists()->attach($listId, ['type' => 'include']);
        }
        foreach ($validated['exclude_lists'] ?? [] as $listId) {
            $campaign->lists()->attach($listId, ['type' => 'exclude']);
        }

        $this->campaignService->prepareCampaign($campaign);

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('email.campaigns.show', $campaign)
            ->with('success', 'Campanha atualizada!');
    }

    public function destroy(EmailCampaign $campaign)
    {
        if ($campaign->isSending()) {
            return back()->with('error', 'Não é possível excluir uma campanha em envio.');
        }

        $campaign->delete();
        return redirect()->route('email.campaigns.index')
            ->with('success', 'Campanha removida.');
    }

    /**
     * Enviar campanha
     */
    public function send(EmailCampaign $campaign)
    {
        if (!$campaign->canSend()) {
            return back()->with('error', 'Esta campanha não pode ser enviada. Verifique assunto, conteúdo e listas.');
        }

        $this->campaignService->startCampaign($campaign);

        return back()->with('success', 'Campanha iniciada! Os envios estão sendo processados.');
    }

    /**
     * Agendar envio
     */
    public function schedule(Request $request, EmailCampaign $campaign)
    {
        $request->validate(['scheduled_at' => 'required|date|after:now']);

        $scheduledAt = \Carbon\Carbon::parse($request->input('scheduled_at'))->setTimezone(config('app.timezone'));

        $campaign->update([
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
        ]);

        SystemLog::info('email', 'campaign.scheduled', "Campanha \"{$campaign->name}\" agendada para " . $scheduledAt->format('d/m/Y H:i') . ' (BRT)', [
            'campaign_id' => $campaign->id,
            'scheduled_at' => $scheduledAt->toIso8601String(),
            'scheduled_at_utc' => $scheduledAt->utc()->toIso8601String(),
        ]);

        return back()->with('success', 'Campanha agendada!');
    }

    /**
     * Editar agendamento
     */
    public function updateSchedule(Request $request, EmailCampaign $campaign)
    {
        if (!$campaign->isScheduled()) {
            return back()->with('error', 'Esta campanha não está agendada.');
        }

        $request->validate(['scheduled_at' => 'required|date|after:now']);

        $oldSchedule = $campaign->scheduled_at;
        $newSchedule = \Carbon\Carbon::parse($request->input('scheduled_at'))->setTimezone(config('app.timezone'));

        $campaign->update([
            'scheduled_at' => $newSchedule,
        ]);

        SystemLog::info('email', 'campaign.schedule_updated', "Agendamento da campanha \"{$campaign->name}\" alterado para " . $newSchedule->format('d/m/Y H:i') . ' (BRT)', [
            'campaign_id' => $campaign->id,
            'old_scheduled_at' => $oldSchedule,
            'new_scheduled_at' => $newSchedule,
        ]);

        return back()->with('success', 'Agendamento atualizado!');
    }

    /**
     * Enviar agora (cancela agendamento e envia imediatamente)
     */
    public function sendNow(EmailCampaign $campaign)
    {
        SystemLog::info('email', 'campaign.send_now.request', "Requisição de envio imediato recebida para campanha \"{$campaign->name}\"", [
            'campaign_id' => $campaign->id,
            'current_status' => $campaign->status,
            'is_scheduled' => $campaign->isScheduled(),
            'can_send' => $campaign->canSend(),
        ]);

        if (!$campaign->isScheduled()) {
            SystemLog::warning('email', 'campaign.send_now.not_scheduled', "Tentativa de enviar agora uma campanha que não está agendada", [
                'campaign_id' => $campaign->id,
                'current_status' => $campaign->status,
            ]);
            return back()->with('error', 'Esta campanha não está agendada.');
        }

        if (!$campaign->canSend()) {
            SystemLog::warning('email', 'campaign.send_now.cannot_send', "Tentativa de enviar campanha que não pode ser enviada", [
                'campaign_id' => $campaign->id,
                'has_subject' => !empty($campaign->subject),
                'has_content' => !empty($campaign->html_content),
                'has_lists' => $campaign->lists()->count() > 0,
            ]);
            return back()->with('error', 'Esta campanha não pode ser enviada. Verifique assunto, conteúdo e listas.');
        }

        SystemLog::info('email', 'campaign.send_now.starting', "Iniciando envio manual da campanha \"{$campaign->name}\"", [
            'campaign_id' => $campaign->id,
            'was_scheduled_for' => $campaign->scheduled_at,
            'total_recipients' => $campaign->total_recipients,
        ]);

        try {
            // Remove o agendamento e inicia o envio
            $campaign->update([
                'status' => 'draft',
                'scheduled_at' => null,
            ]);

            SystemLog::info('email', 'campaign.send_now.status_updated', "Status da campanha alterado para draft antes do envio", [
                'campaign_id' => $campaign->id,
            ]);

            $this->campaignService->startCampaign($campaign);

            SystemLog::info('email', 'campaign.send_now.success', "Campanha \"{$campaign->name}\" iniciada com sucesso", [
                'campaign_id' => $campaign->id,
                'new_status' => $campaign->fresh()->status,
            ]);

            return back()->with('success', 'Campanha iniciada! Os envios estão sendo processados.');
        } catch (\Throwable $e) {
            SystemLog::error('email', 'campaign.send_now.failed', "Erro ao iniciar envio da campanha \"{$campaign->name}\": {$e->getMessage()}", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Erro ao iniciar o envio: ' . $e->getMessage());
        }
    }

    /**
     * Pausar envio
     */
    public function pause(EmailCampaign $campaign)
    {
        if (!$campaign->canPause()) {
            return back()->with('error', 'Não é possível pausar esta campanha.');
        }

        $this->campaignService->pauseCampaign($campaign);
        return back()->with('success', 'Campanha pausada.');
    }

    /**
     * Cancelar envio
     */
    public function cancel(EmailCampaign $campaign)
    {
        if (!$campaign->canCancel()) {
            return back()->with('error', 'Não é possível cancelar esta campanha.');
        }

        $this->campaignService->cancelCampaign($campaign);
        return back()->with('success', 'Campanha cancelada.');
    }

    /**
     * Reenviar emails que falharam por quota excedida
     */
    public function retryFailed(EmailCampaign $campaign)
    {
        SystemLog::info('email', 'campaign.retry_failed.request', "Requisição para reenviar falhas da campanha \"{$campaign->name}\"", [
            'campaign_id' => $campaign->id,
            'current_status' => $campaign->status,
        ]);

        // Buscar eventos 'failed' que foram marcados por quota excedida
        $failedEvents = $campaign->events()
            ->where('event_type', 'failed')
            ->where(function ($query) {
                $query->where('metadata->reason', 'quota_exceeded')
                    ->orWhere('metadata->error', 'like', '%Limite por hora atingido%')
                    ->orWhere('metadata->error', 'like', '%quota%');
            })
            ->with('contact')
            ->get();

        if ($failedEvents->isEmpty()) {
            return back()->with('error', 'Não há emails para reenviar. Todos já foram processados ou não há falhas por quota.');
        }

        $contactIds = $failedEvents->pluck('email_contact_id')->toArray();

        SystemLog::info('email', 'campaign.retry_failed.found', "Encontrados {$failedEvents->count()} emails para reenviar", [
            'campaign_id' => $campaign->id,
            'failed_count' => $failedEvents->count(),
            'contact_ids_sample' => array_slice($contactIds, 0, 10),
        ]);

        // Atualizar status da campanha se necessário
        if (in_array($campaign->status, ['sent', 'cancelled'])) {
            $campaign->update([
                'status' => 'sending',
                'completed_at' => null,
            ]);
        }

        // Remover os eventos 'failed' antigos
        \App\Models\EmailCampaignEvent::where('email_campaign_id', $campaign->id)
            ->whereIn('email_contact_id', $contactIds)
            ->where('event_type', 'failed')
            ->delete();

        // Verificar quais contatos já têm evento 'sent' e removê-los da lista
        $alreadySent = \App\Models\EmailCampaignEvent::where('email_campaign_id', $campaign->id)
            ->whereIn('email_contact_id', $contactIds)
            ->where('event_type', 'sent')
            ->pluck('email_contact_id')
            ->toArray();

        $contactIds = array_diff($contactIds, $alreadySent);

        if (empty($contactIds)) {
            SystemLog::info('email', 'campaign.retry_failed.all_sent', 'Todos os emails já foram enviados anteriormente', [
                'campaign_id' => $campaign->id,
            ]);
            return back()->with('success', 'Todos os emails da lista já foram enviados anteriormente. Nenhum reenvio necessário.');
        }

        // Remover eventos 'queued' antigos destes contatos (para evitar duplicação na contagem)
        \App\Models\EmailCampaignEvent::where('email_campaign_id', $campaign->id)
            ->whereIn('email_contact_id', $contactIds)
            ->where('event_type', 'queued')
            ->delete();

        // Criar novos eventos 'queued' para os contatos que não foram enviados
        $now = now();
        $queuedEvents = collect($contactIds)->map(fn($contactId) => [
            'email_campaign_id' => $campaign->id,
            'email_contact_id' => $contactId,
            'event_type' => 'queued',
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->toArray();

        foreach (array_chunk($queuedEvents, 500) as $chunk) {
            \App\Models\EmailCampaignEvent::insert($chunk);
        }

        // Configurar batches respeitando quota do provedor
        $provider = $campaign->provider;
        $hourlyLimit = $provider?->hourly_limit;

        if ($hourlyLimit && $hourlyLimit > 0) {
            $batchSize = min($hourlyLimit, 100);
            $delayBetweenBatches = 3600; // 1 hora

            SystemLog::info('email', 'campaign.retry_failed.rate_limit', "Modo de respeito à quota ativado para reenvio: {$hourlyLimit} emails/hora", [
                'campaign_id' => $campaign->id,
                'hourly_limit' => $hourlyLimit,
                'batch_size' => $batchSize,
            ]);
        } else {
            $sendSpeed = $campaign->getSetting('send_speed', 100);
            $batchSize = min($sendSpeed, 100);
            $delayBetweenBatches = 60; // 1 minuto
        }

        foreach (array_chunk($contactIds, $batchSize) as $index => $batchIds) {
            $delay = $index * $delayBetweenBatches;
            \App\Jobs\SendCampaignBatchJob::dispatch($campaign->id, $batchIds)
                ->delay(now()->addSeconds($delay))
                ->onQueue('email');
        }

        $batchesCount = ceil(count($contactIds) / $batchSize);
        $etaMessage = $hourlyLimit
            ? "~" . ceil($batchesCount) . " horas (respeitando limite de {$hourlyLimit}/hora)"
            : "~" . ceil($batchesCount) . " minutos";

        SystemLog::info('email', 'campaign.retry_failed.started', "Reenvio iniciado: {$failedEvents->count()} emails em {$batchesCount} batches", [
            'campaign_id' => $campaign->id,
            'retry_count' => $failedEvents->count(),
            'batches' => $batchesCount,
            'hourly_limit' => $hourlyLimit,
            'eta' => $etaMessage,
        ]);

        return back()->with('success', "Reenvio iniciado! {$failedEvents->count()} emails serão reprocessados em {$batchesCount} batches. Tempo estimado: {$etaMessage}.");
    }

    /**
     * Duplicar campanha
     */
    public function duplicate(EmailCampaign $campaign)
    {
        $new = $this->campaignService->duplicate($campaign);
        return redirect()->route('email.campaigns.edit', $new)
            ->with('success', 'Campanha duplicada!');
    }

    /**
     * Enviar teste
     */
    public function sendTest(Request $request, EmailCampaign $campaign)
    {
        $request->validate(['test_email' => 'required|email']);

        $provider = $campaign->provider;
        if (!$provider) {
            return response()->json(['success' => false, 'error' => 'Nenhum provedor configurado.']);
        }

        $html = $this->inlineCssForEmail($campaign->html_content ?? '<p>Sem conteúdo</p>');

        // Usar o email correto do provedor (especialmente para SendPulse)
        $campaignService = app(\App\Services\Email\EmailCampaignService::class);
        $fromEmail = $campaignService->resolveFromEmail($campaign);
        $fromName = $campaign->from_name ?: $provider->getFromName() ?: config('app.name');

        SystemLog::info('email', 'campaign.test.start', "Iniciando envio de teste da campanha \"{$campaign->name}\"", [
            'campaign_id' => $campaign->id,
            'test_email' => $request->input('test_email'),
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'provider_type' => $provider->type,
            'provider_id' => $provider->id,
        ]);

        $providerService = app(\App\Services\Email\EmailProviderService::class);
        $result = $providerService->send(
            $provider,
            $request->input('test_email'),
            '[TESTE] ' . $campaign->subject,
            $html,
            $fromName,
            $fromEmail,
        );

        if ($result['success']) {
            SystemLog::info('email', 'campaign.test.success', "Envio de teste da campanha \"{$campaign->name}\" realizado com sucesso", [
                'campaign_id' => $campaign->id,
                'test_email' => $request->input('test_email'),
                'from_email' => $fromEmail,
                'message_id' => $result['message_id'] ?? null,
            ]);
        } else {
            SystemLog::error('email', 'campaign.test.failed', "Falha no envio de teste da campanha \"{$campaign->name}\"", [
                'campaign_id' => $campaign->id,
                'test_email' => $request->input('test_email'),
                'from_email' => $fromEmail,
                'error' => $result['error'] ?? 'Unknown error',
            ]);
        }

        return response()->json($result);
    }

    /**
     * Enviar teste avulso (sem campanha salva — usado na criação)
     */
    public function sendTestPreview(Request $request)
    {
        try {
            $request->validate([
                'test_email' => 'required|email',
                'subject' => 'required|string|max:255',
                'html_content' => 'required|string',
                'email_provider_id' => 'required',
                'from_name' => 'nullable|string|max:255',
                'from_email' => 'nullable|email|max:255',
            ]);

            $provider = EmailProvider::find($request->input('email_provider_id'));
            if (!$provider) {
                SystemLog::warning('email', 'campaign.test_preview.provider_not_found', 'Provedor não encontrado para envio de teste', [
                    'provider_id' => $request->input('email_provider_id'),
                ]);
                return response()->json(['success' => false, 'error' => 'Provedor não encontrado. ID: ' . $request->input('email_provider_id')]);
            }

            $html = $this->inlineCssForEmail($request->input('html_content'));

            // Para SendPulse, sempre usar o email configurado no provedor
            $configFromEmail = $provider->config['from_email'] ?? $provider->config['from_address'] ?? null;
            if ($provider->type === 'sendpulse' && $configFromEmail) {
                $fromEmail = $configFromEmail;
            } else {
                $fromEmail = $request->input('from_email') ?: $provider->getFromEmail() ?: config('mail.from.address');
            }
            $fromName = $request->input('from_name') ?: $provider->getFromName() ?: config('app.name');

            SystemLog::info('email', 'campaign.test_preview.start', 'Iniciando envio de teste (preview)', [
                'test_email' => $request->input('test_email'),
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'provider_type' => $provider->type,
                'provider_id' => $provider->id,
                'subject' => $request->input('subject'),
            ]);

            $providerService = app(\App\Services\Email\EmailProviderService::class);
            $result = $providerService->send(
                $provider,
                $request->input('test_email'),
                '[TESTE] ' . $request->input('subject'),
                $html,
                $fromName,
                $fromEmail,
            );

            if ($result['success']) {
                SystemLog::info('email', 'campaign.test_preview.success', 'Envio de teste (preview) realizado com sucesso', [
                    'test_email' => $request->input('test_email'),
                    'from_email' => $fromEmail,
                    'message_id' => $result['message_id'] ?? null,
                ]);
            } else {
                SystemLog::error('email', 'campaign.test_preview.failed', 'Falha no envio de teste (preview)', [
                    'test_email' => $request->input('test_email'),
                    'from_email' => $fromEmail,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }

            return response()->json($result);
        } catch (\Illuminate\Validation\ValidationException $e) {
            SystemLog::warning('email', 'campaign.test_preview.validation_failed', 'Validação falhou no envio de teste preview', [
                'errors' => $e->errors(),
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Validação: ' . collect($e->errors())->flatten()->implode(', '),
            ], 422);
        } catch (\Throwable $e) {
            SystemLog::error('email', 'campaign.test_preview.error', 'Erro interno no envio de teste preview', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Erro interno: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Converte CSS de <style> tags para inline styles.
     */
    private function inlineCssForEmail(string $html): string
    {
        if (empty($html)) {
            return $html;
        }

        try {
            $css = '';
            if (preg_match('/<style[^>]*>(.*?)<\/style>/si', $html, $matches)) {
                $css = $matches[1];
            }

            $inliner = new \TijsVerkoyen\CssToInlineStyles\CssToInlineStyles();
            return $inliner->convert($html, $css) ?: $html;
        } catch (\Throwable) {
            return $html;
        }
    }
}

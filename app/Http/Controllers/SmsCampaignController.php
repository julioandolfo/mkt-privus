<?php

namespace App\Http\Controllers;

use App\Models\EmailList;
use App\Models\EmailProvider;
use App\Models\SmsCampaign;
use App\Models\SmsTemplate;
use App\Services\Sms\SmsCampaignService;
use App\Services\Sms\SmsProviderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class SmsCampaignController extends Controller
{
    public function __construct(
        private SmsCampaignService $campaignService,
        private SmsProviderService $smsProviderService,
    ) {}

    public function index(Request $request)
    {
        if (!Schema::hasTable('sms_campaigns')) {
            return Inertia::render('Sms/Campaigns/Index', [
                'campaigns' => ['data' => [], 'links' => [], 'last_page' => 1, 'total' => 0],
                'filters' => [],
                'migrationPending' => true,
            ]);
        }

        $brandId = session('current_brand_id');

        $campaigns = SmsCampaign::where('brand_id', $brandId)
            ->with(['provider:id,name', 'template:id,name', 'includeLists:id,name'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status,
                'sender_name' => $c->sender_name,
                'total_recipients' => $c->total_recipients,
                'total_sent' => $c->total_sent,
                'total_delivered' => $c->total_delivered,
                'total_failed' => $c->total_failed,
                'delivery_rate' => $c->delivery_rate,
                'scheduled_at' => $c->scheduled_at?->format('d/m/Y H:i'),
                'started_at' => $c->started_at?->format('d/m/Y H:i'),
                'completed_at' => $c->completed_at?->format('d/m/Y H:i'),
                'provider_name' => $c->provider?->name,
                'template_name' => $c->template?->name,
                'lists' => $c->includeLists->pluck('name')->toArray(),
                'created_at' => $c->created_at->format('d/m/Y H:i'),
                'segments' => $c->segments,
                'estimated_cost' => $c->estimated_cost,
            ]);

        return Inertia::render('Sms/Campaigns/Index', [
            'campaigns' => $campaigns,
            'filters' => $request->only('status', 'search'),
        ]);
    }

    public function create()
    {
        $brandId = session('current_brand_id');

        return Inertia::render('Sms/Campaigns/Create', [
            'providers' => EmailProvider::where('brand_id', $brandId)
                ->where('type', 'sms_sendpulse')
                ->where('is_active', true)
                ->get(['id', 'name']),
            'templates' => Schema::hasTable('sms_templates')
                ? SmsTemplate::where('brand_id', $brandId)
                    ->active()
                    ->orderByDesc('created_at')
                    ->get(['id', 'name', 'body', 'category'])
                : [],
            'lists' => EmailList::where('brand_id', $brandId)
                ->get(['id', 'name', 'contacts_count']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email_provider_id' => 'required|exists:email_providers,id',
            'sms_template_id' => 'nullable|exists:sms_templates,id',
            'body' => 'required|string|max:1600', // Max 10 segmentos
            'sender_name' => 'required|string|max:11',
            'scheduled_at' => 'nullable|date|after:now',
            'list_ids' => 'required|array|min:1',
            'list_ids.*' => 'exists:email_lists,id',
            'exclude_list_ids' => 'nullable|array',
            'exclude_list_ids.*' => 'exists:email_lists,id',
            'settings' => 'nullable|array',
            'tags' => 'nullable|array',
        ]);

        $brandId = session('current_brand_id');

        $campaign = SmsCampaign::create([
            'brand_id' => $brandId,
            'user_id' => Auth::id(),
            'email_provider_id' => $validated['email_provider_id'],
            'sms_template_id' => $validated['sms_template_id'] ?? null,
            'name' => $validated['name'],
            'body' => $validated['body'],
            'sender_name' => $validated['sender_name'],
            'status' => $validated['scheduled_at'] ? 'scheduled' : 'draft',
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'settings' => $validated['settings'] ?? [],
            'tags' => $validated['tags'] ?? [],
        ]);

        // Attach lists
        foreach ($validated['list_ids'] as $listId) {
            $campaign->lists()->attach($listId, ['type' => 'include']);
        }
        foreach (($validated['exclude_list_ids'] ?? []) as $listId) {
            $campaign->lists()->attach($listId, ['type' => 'exclude']);
        }

        // Calcular destinatários
        $this->campaignService->calculateRecipients($campaign);

        return redirect()->route('sms.campaigns.show', $campaign)
            ->with('success', 'Campanha SMS criada com sucesso!');
    }

    public function show(SmsCampaign $campaign)
    {
        $campaign->load(['provider:id,name', 'template:id,name,body', 'includeLists:id,name', 'excludeLists:id,name']);

        // Calcular custo estimado
        $costEstimate = $this->campaignService->estimateCost($campaign);

        // Eventos recentes
        $recentEvents = $campaign->events()
            ->with('contact:id,first_name,last_name,phone')
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'phone' => $e->phone,
                'contact_name' => $e->contact ? "{$e->contact->first_name} {$e->contact->last_name}" : '-',
                'occurred_at' => $e->occurred_at->format('d/m/Y H:i:s'),
                'metadata' => $e->metadata,
            ]);

        return Inertia::render('Sms/Campaigns/Show', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'body' => $campaign->body,
                'sender_name' => $campaign->sender_name,
                'status' => $campaign->status,
                'type' => $campaign->type,
                'scheduled_at' => $campaign->scheduled_at?->format('Y-m-d\TH:i'),
                'started_at' => $campaign->started_at?->format('d/m/Y H:i'),
                'completed_at' => $campaign->completed_at?->format('d/m/Y H:i'),
                'total_recipients' => $campaign->total_recipients,
                'total_sent' => $campaign->total_sent,
                'total_delivered' => $campaign->total_delivered,
                'total_failed' => $campaign->total_failed,
                'total_clicked' => $campaign->total_clicked,
                'delivery_rate' => $campaign->delivery_rate,
                'failure_rate' => $campaign->failure_rate,
                'click_rate' => $campaign->click_rate,
                'segments' => $campaign->segments,
                'estimated_cost' => $campaign->estimated_cost,
                'settings' => $campaign->settings,
                'tags' => $campaign->tags,
                'provider' => $campaign->provider,
                'template' => $campaign->template,
                'include_lists' => $campaign->includeLists,
                'exclude_lists' => $campaign->excludeLists,
                'can_edit' => $campaign->canEdit(),
                'can_send' => $campaign->canSend(),
                'can_pause' => $campaign->canPause(),
                'can_cancel' => $campaign->canCancel(),
                'created_at' => $campaign->created_at->format('d/m/Y H:i'),
            ],
            'costEstimate' => $costEstimate,
            'recentEvents' => $recentEvents,
        ]);
    }

    public function send(SmsCampaign $campaign)
    {
        if ($this->campaignService->startCampaign($campaign)) {
            return back()->with('success', 'Campanha SMS iniciada! Os SMS estão sendo enviados.');
        }

        return back()->with('error', 'Não foi possível iniciar a campanha. Verifique se tem destinatários e provedor configurado.');
    }

    public function schedule(Request $request, SmsCampaign $campaign)
    {
        $validated = $request->validate([
            'scheduled_at' => 'required|date|after:now',
        ]);

        $campaign->update([
            'status' => 'scheduled',
            'scheduled_at' => $validated['scheduled_at'],
        ]);

        return back()->with('success', 'Campanha agendada para ' . $campaign->scheduled_at->format('d/m/Y H:i'));
    }

    public function pause(SmsCampaign $campaign)
    {
        if ($this->campaignService->pauseCampaign($campaign)) {
            return back()->with('success', 'Campanha pausada.');
        }

        return back()->with('error', 'Não foi possível pausar.');
    }

    public function cancel(SmsCampaign $campaign)
    {
        if ($this->campaignService->cancelCampaign($campaign)) {
            return back()->with('success', 'Campanha cancelada.');
        }

        return back()->with('error', 'Não foi possível cancelar.');
    }

    public function duplicate(SmsCampaign $campaign)
    {
        $new = $this->campaignService->duplicateCampaign($campaign);

        return redirect()->route('sms.campaigns.show', $new)
            ->with('success', 'Campanha duplicada!');
    }

    public function destroy(SmsCampaign $campaign)
    {
        if (!$campaign->canEdit()) {
            return back()->with('error', 'Apenas campanhas em rascunho podem ser removidas.');
        }

        $campaign->events()->delete();
        $campaign->lists()->detach();
        $campaign->delete();

        return redirect()->route('sms.campaigns.index')
            ->with('success', 'Campanha removida.');
    }

    /**
     * API: Estimar custo
     */
    public function estimateCost(SmsCampaign $campaign)
    {
        $cost = $this->campaignService->estimateCost($campaign);
        return response()->json($cost);
    }

    /**
     * API: Calcular segmentos de um texto
     */
    public function calculateSegments(Request $request)
    {
        $text = $request->input('text', '');
        $isUnicode = SmsTemplate::isUnicode($text);
        $segments = SmsTemplate::calculateSegments($text);
        $charCount = mb_strlen($text);

        $maxCharsPerSegment = $isUnicode ? 70 : 160;
        $maxCharsConcat = $isUnicode ? 67 : 153;

        return response()->json([
            'char_count' => $charCount,
            'segments' => $segments,
            'is_unicode' => $isUnicode,
            'encoding' => $isUnicode ? 'Unicode (UCS-2)' : 'GSM-7',
            'max_per_segment' => $segments <= 1 ? $maxCharsPerSegment : $maxCharsConcat,
            'remaining' => $segments <= 1
                ? $maxCharsPerSegment - $charCount
                : ($segments * $maxCharsConcat) - $charCount,
        ]);
    }
}

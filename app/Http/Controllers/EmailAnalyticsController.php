<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignEvent;
use App\Models\EmailContact;
use App\Models\EmailList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EmailAnalyticsController extends Controller
{
    public function dashboard(Request $request)
    {
        $brandId = session('current_brand_id');
        $period = $request->input('period', 'this_month');
        $dates = $this->parsePeriod($period, $request);

        // Metricas gerais
        $campaigns = EmailCampaign::forBrand($brandId);
        $campaignsInPeriod = (clone $campaigns)->whereBetween('created_at', [$dates['start'], $dates['end']]);

        $totalCampaigns = $campaignsInPeriod->count();
        $sentCampaigns = (clone $campaignsInPeriod)->where('status', 'sent')->count();

        // Agregados
        $sentCampaignData = EmailCampaign::forBrand($brandId)
            ->whereIn('status', ['sent', 'sending'])
            ->whereBetween('started_at', [$dates['start'], $dates['end']]);

        $aggregated = (clone $sentCampaignData)->selectRaw('
            SUM(total_sent) as total_sent,
            SUM(total_delivered) as total_delivered,
            SUM(total_bounced) as total_bounced,
            SUM(total_opened) as total_opened,
            SUM(total_clicked) as total_clicked,
            SUM(total_unsubscribed) as total_unsubscribed,
            SUM(total_complained) as total_complained,
            SUM(unique_opens) as unique_opens,
            SUM(unique_clicks) as unique_clicks,
            SUM(total_recipients) as total_recipients
        ')->first();

        $totalSent = (int) ($aggregated->total_sent ?? 0);
        $totalDelivered = (int) ($aggregated->total_delivered ?? 0);
        $totalOpened = (int) ($aggregated->total_opened ?? 0);
        $totalClicked = (int) ($aggregated->total_clicked ?? 0);
        $uniqueOpens = (int) ($aggregated->unique_opens ?? 0);
        $uniqueClicks = (int) ($aggregated->unique_clicks ?? 0);

        $overallStats = [
            'total_campaigns' => $totalCampaigns,
            'sent_campaigns' => $sentCampaigns,
            'total_recipients' => (int) ($aggregated->total_recipients ?? 0),
            'total_sent' => $totalSent,
            'total_delivered' => $totalDelivered,
            'total_bounced' => (int) ($aggregated->total_bounced ?? 0),
            'total_opened' => $totalOpened,
            'total_clicked' => $totalClicked,
            'total_unsubscribed' => (int) ($aggregated->total_unsubscribed ?? 0),
            'unique_opens' => $uniqueOpens,
            'unique_clicks' => $uniqueClicks,
            'open_rate' => $totalDelivered > 0 ? round(($uniqueOpens / $totalDelivered) * 100, 2) : 0,
            'click_rate' => $totalDelivered > 0 ? round(($uniqueClicks / $totalDelivered) * 100, 2) : 0,
            'bounce_rate' => $totalSent > 0 ? round(((int)($aggregated->total_bounced ?? 0) / $totalSent) * 100, 2) : 0,
            'delivery_rate' => $totalSent > 0 ? round(($totalDelivered / $totalSent) * 100, 2) : 0,
        ];

        // Grafico diario de envios
        $dailyChart = EmailCampaignEvent::whereHas('campaign', function ($q) use ($brandId) {
            $q->forBrand($brandId);
        })
            ->whereBetween('occurred_at', [$dates['start'], $dates['end']])
            ->selectRaw("DATE(occurred_at) as date, event_type, COUNT(*) as count")
            ->groupBy('date', 'event_type')
            ->orderBy('date')
            ->get()
            ->groupBy('date')
            ->map(fn($events) => [
                'date' => $events->first()->date,
                'sent' => $events->where('event_type', 'sent')->sum('count'),
                'delivered' => $events->where('event_type', 'delivered')->sum('count'),
                'opened' => $events->where('event_type', 'opened')->sum('count'),
                'clicked' => $events->where('event_type', 'clicked')->sum('count'),
                'bounced' => $events->where('event_type', 'bounced')->sum('count'),
            ])
            ->values();

        // Top campanhas
        $topCampaigns = EmailCampaign::forBrand($brandId)
            ->whereIn('status', ['sent', 'sending'])
            ->whereBetween('started_at', [$dates['start'], $dates['end']])
            ->orderByDesc('unique_opens')
            ->limit(10)
            ->get(['id', 'name', 'subject', 'status', 'total_sent', 'total_delivered', 'unique_opens', 'unique_clicks', 'started_at'])
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'subject' => $c->subject,
                'total_sent' => $c->total_sent,
                'total_delivered' => $c->total_delivered,
                'unique_opens' => $c->unique_opens,
                'unique_clicks' => $c->unique_clicks,
                'open_rate' => $c->open_rate,
                'click_rate' => $c->click_rate,
                'started_at' => $c->started_at?->format('d/m/Y'),
            ]);

        // Contatos stats
        $contactStats = [
            'total' => EmailContact::forBrand($brandId)->count(),
            'active' => EmailContact::forBrand($brandId)->where('status', 'active')->count(),
            'unsubscribed' => EmailContact::forBrand($brandId)->where('status', 'unsubscribed')->count(),
            'bounced' => EmailContact::forBrand($brandId)->where('status', 'bounced')->count(),
            'lists' => EmailList::forBrand($brandId)->count(),
        ];

        // Periodo anterior para comparacao
        $prevDates = $this->getPreviousPeriod($dates);
        $prevAgg = EmailCampaign::forBrand($brandId)
            ->whereIn('status', ['sent', 'sending'])
            ->whereBetween('started_at', [$prevDates['start'], $prevDates['end']])
            ->selectRaw('
                SUM(total_sent) as total_sent,
                SUM(total_delivered) as total_delivered,
                SUM(unique_opens) as unique_opens,
                SUM(unique_clicks) as unique_clicks
            ')->first();

        $prevDelivered = (int) ($prevAgg->total_delivered ?? 0);
        $prevUniqueOpens = (int) ($prevAgg->unique_opens ?? 0);

        $comparison = [
            'prev_sent' => (int) ($prevAgg->total_sent ?? 0),
            'prev_delivered' => $prevDelivered,
            'prev_open_rate' => $prevDelivered > 0 ? round(($prevUniqueOpens / $prevDelivered) * 100, 2) : 0,
            'prev_click_rate' => $prevDelivered > 0 ? round(((int)($prevAgg->unique_clicks ?? 0) / $prevDelivered) * 100, 2) : 0,
        ];

        return Inertia::render('Email/Dashboard', [
            'overallStats' => $overallStats,
            'dailyChart' => $dailyChart,
            'topCampaigns' => $topCampaigns,
            'contactStats' => $contactStats,
            'comparison' => $comparison,
            'period' => $period,
            'dates' => $dates,
        ]);
    }

    /**
     * Analytics detalhado por campanha
     */
    public function campaignAnalytics(EmailCampaign $campaign)
    {
        $campaign->refreshStats();

        // Eventos por hora
        $hourlyData = $campaign->events()
            ->selectRaw("DATE_FORMAT(occurred_at, '%Y-%m-%d %H:00') as hour, event_type, COUNT(*) as count")
            ->groupBy('hour', 'event_type')
            ->orderBy('hour')
            ->get()
            ->groupBy('hour')
            ->map(fn($events) => [
                'hour' => $events->first()->hour,
                'sent' => $events->where('event_type', 'sent')->sum('count'),
                'opened' => $events->where('event_type', 'opened')->sum('count'),
                'clicked' => $events->where('event_type', 'clicked')->sum('count'),
            ])
            ->values();

        // Top links clicados
        $topLinks = $campaign->events()
            ->where('event_type', 'clicked')
            ->whereNotNull('metadata->url')
            ->selectRaw("JSON_EXTRACT(metadata, '$.url') as url, COUNT(*) as clicks, COUNT(DISTINCT email_contact_id) as unique_clicks")
            ->groupBy('url')
            ->orderByDesc('clicks')
            ->limit(10)
            ->get();

        // Clientes que abriram
        $openedContacts = $campaign->events()
            ->where('event_type', 'opened')
            ->with('contact:id,email,first_name,last_name')
            ->select('email_contact_id')
            ->distinct()
            ->limit(50)
            ->get()
            ->pluck('contact')
            ->filter()
            ->map(fn($c) => ['email' => $c->email, 'name' => $c->full_name]);

        return response()->json([
            'hourlyData' => $hourlyData,
            'topLinks' => $topLinks,
            'openedContacts' => $openedContacts,
        ]);
    }

    // ===== HELPERS =====

    private function parsePeriod(string $period, Request $request): array
    {
        return match ($period) {
            'today' => ['start' => now()->startOfDay(), 'end' => now()->endOfDay()],
            'yesterday' => ['start' => now()->subDay()->startOfDay(), 'end' => now()->subDay()->endOfDay()],
            'this_week' => ['start' => now()->startOfWeek(), 'end' => now()->endOfWeek()],
            'this_month' => ['start' => now()->startOfMonth(), 'end' => now()->endOfMonth()],
            'last_month' => ['start' => now()->subMonth()->startOfMonth(), 'end' => now()->subMonth()->endOfMonth()],
            'last_30' => ['start' => now()->subDays(30), 'end' => now()],
            'last_90' => ['start' => now()->subDays(90), 'end' => now()],
            'custom' => [
                'start' => $request->input('start_date', now()->subMonth()),
                'end' => $request->input('end_date', now()),
            ],
            default => ['start' => now()->startOfMonth(), 'end' => now()->endOfMonth()],
        };
    }

    private function getPreviousPeriod(array $current): array
    {
        $start = \Carbon\Carbon::parse($current['start']);
        $end = \Carbon\Carbon::parse($current['end']);
        $diff = $start->diffInDays($end);

        return [
            'start' => $start->copy()->subDays($diff + 1),
            'end' => $start->copy()->subDay(),
        ];
    }
}

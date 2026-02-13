<?php

namespace App\Http\Controllers;

use App\Models\SmsCampaign;
use App\Models\SmsCampaignEvent;
use App\Models\SmsTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class SmsDashboardController extends Controller
{
    public function index(Request $request)
    {
        // Verificar se as tabelas SMS existem (migration pode nao ter rodado)
        if (!Schema::hasTable('sms_campaigns')) {
            return Inertia::render('Sms/Dashboard', [
                'kpis' => [
                    'total_campaigns' => 0, 'total_sent' => 0, 'total_delivered' => 0,
                    'total_failed' => 0, 'total_clicked' => 0, 'delivery_rate' => 0,
                    'failure_rate' => 0, 'click_rate' => 0,
                ],
                'recentCampaigns' => [],
                'dailyStats' => [],
                'statusDistribution' => [],
                'period' => 'this_month',
                'templates_count' => 0,
                'migrationPending' => true,
            ]);
        }

        $brandId = session('current_brand_id');
        $period = $request->input('period', 'this_month');

        [$startDate, $endDate] = $this->resolvePeriod($period);

        // KPIs gerais
        $campaigns = SmsCampaign::where('brand_id', $brandId);
        $periodCampaigns = (clone $campaigns)->whereBetween('created_at', [$startDate, $endDate]);

        $totalCampaigns = $periodCampaigns->count();
        $totalSent = (clone $periodCampaigns)->sum('total_sent');
        $totalDelivered = (clone $periodCampaigns)->sum('total_delivered');
        $totalFailed = (clone $periodCampaigns)->sum('total_failed');
        $totalClicked = (clone $periodCampaigns)->sum('total_clicked');

        $deliveryRate = $totalSent > 0 ? round(($totalDelivered / $totalSent) * 100, 1) : 0;
        $failureRate = $totalSent > 0 ? round(($totalFailed / $totalSent) * 100, 1) : 0;
        $clickRate = $totalDelivered > 0 ? round(($totalClicked / $totalDelivered) * 100, 1) : 0;

        // Campanhas recentes
        $recentCampaigns = SmsCampaign::where('brand_id', $brandId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status,
                'total_sent' => $c->total_sent,
                'total_delivered' => $c->total_delivered,
                'delivery_rate' => $c->delivery_rate,
                'created_at' => $c->created_at->format('d/m/Y H:i'),
            ]);

        // Gráfico de envios por dia
        $dailyStats = SmsCampaignEvent::whereHas('campaign', fn($q) => $q->where('brand_id', $brandId))
            ->whereBetween('occurred_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(occurred_at) as date'),
                DB::raw("SUM(CASE WHEN event_type = 'sent' THEN 1 ELSE 0 END) as sent"),
                DB::raw("SUM(CASE WHEN event_type = 'delivered' THEN 1 ELSE 0 END) as delivered"),
                DB::raw("SUM(CASE WHEN event_type = 'failed' THEN 1 ELSE 0 END) as failed"),
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Status distribution
        $statusDistribution = SmsCampaign::where('brand_id', $brandId)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('Sms/Dashboard', [
            'kpis' => [
                'total_campaigns' => $totalCampaigns,
                'total_sent' => $totalSent,
                'total_delivered' => $totalDelivered,
                'total_failed' => $totalFailed,
                'total_clicked' => $totalClicked,
                'delivery_rate' => $deliveryRate,
                'failure_rate' => $failureRate,
                'click_rate' => $clickRate,
            ],
            'recentCampaigns' => $recentCampaigns,
            'dailyStats' => $dailyStats,
            'statusDistribution' => $statusDistribution,
            'period' => $period,
            'templates_count' => SmsTemplate::where('brand_id', $brandId)->active()->count(),
        ]);
    }

    private function resolvePeriod(string $period): array
    {
        return match ($period) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'yesterday' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'last_7_days' => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
            'this_month' => [now()->startOfMonth(), now()->endOfDay()],
            'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'last_30_days' => [now()->subDays(29)->startOfDay(), now()->endOfDay()],
            default => [now()->startOfMonth(), now()->endOfDay()],
        };
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\SystemLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogsController extends Controller
{
    public function index(Request $request): Response
    {
        $channel = $request->get('channel', 'all');
        $level = $request->get('level', 'all');
        $search = $request->get('search', '');
        $perPage = (int) $request->get('per_page', 50);

        $query = SystemLog::query()
            ->orderByDesc('created_at');

        if ($channel !== 'all') {
            $query->where('channel', $channel);
        }

        if ($level !== 'all') {
            $query->where('level', $level);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('message', 'like', "%{$search}%")
                  ->orWhere('action', 'like', "%{$search}%")
                  ->orWhere('context', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate($perPage)->through(function ($log) {
            return [
                'id' => $log->id,
                'channel' => $log->channel,
                'level' => $log->level,
                'action' => $log->action,
                'message' => $log->message,
                'context' => $log->context,
                'user_id' => $log->user_id,
                'brand_id' => $log->brand_id,
                'ip' => $log->ip,
                'created_at' => $log->created_at->format('d/m/Y H:i:s'),
                'created_at_diff' => $log->created_at->diffForHumans(),
            ];
        });

        // Estatisticas
        $stats = [
            'total' => SystemLog::count(),
            'today' => SystemLog::whereDate('created_at', today())->count(),
            'errors_today' => SystemLog::where('level', 'error')->whereDate('created_at', today())->count(),
            'channels' => SystemLog::selectRaw('channel, count(*) as count')
                ->groupBy('channel')
                ->orderByDesc('count')
                ->pluck('count', 'channel')
                ->toArray(),
        ];

        // Canais disponiveis
        $channels = SystemLog::select('channel')
            ->distinct()
            ->orderBy('channel')
            ->pluck('channel')
            ->toArray();

        return Inertia::render('Logs/Index', [
            'logs' => $logs,
            'stats' => $stats,
            'channels' => $channels,
            'filters' => [
                'channel' => $channel,
                'level' => $level,
                'search' => $search,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Ver detalhes de um log especifico (JSON)
     */
    public function show(SystemLog $log)
    {
        return response()->json([
            'id' => $log->id,
            'channel' => $log->channel,
            'level' => $log->level,
            'action' => $log->action,
            'message' => $log->message,
            'context' => $log->context,
            'user_id' => $log->user_id,
            'brand_id' => $log->brand_id,
            'ip' => $log->ip,
            'created_at' => $log->created_at->toIso8601String(),
        ]);
    }

    /**
     * Limpar logs antigos
     */
    public function cleanup(Request $request)
    {
        $days = (int) $request->get('days', 30);
        $deleted = SystemLog::cleanup($days);

        return redirect()->back()
            ->with('success', "{$deleted} log(s) removido(s) (mais de {$days} dias).");
    }

    /**
     * Limpar todos os logs
     */
    public function clear(Request $request)
    {
        $channel = $request->get('channel');

        if ($channel) {
            $deleted = SystemLog::where('channel', $channel)->delete();
            $msg = "{$deleted} log(s) do canal '{$channel}' removido(s).";
        } else {
            $deleted = SystemLog::truncate();
            $msg = "Todos os logs foram removidos.";
        }

        return redirect()->back()->with('success', $msg);
    }
}

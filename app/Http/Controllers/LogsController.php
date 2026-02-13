<?php

namespace App\Http\Controllers;

use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
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

    /**
     * Retorna o conteúdo do laravel.log parseado (JSON endpoint)
     */
    public function laravelLog(Request $request): JsonResponse
    {
        $logPath = storage_path('logs/laravel.log');

        if (!File::exists($logPath)) {
            return response()->json([
                'entries' => [],
                'file_size' => 0,
                'file_size_human' => '0 B',
            ]);
        }

        $fileSize = File::size($logPath);
        $lines = (int) $request->get('lines', 200);
        $level = $request->get('level', 'all');
        $search = $request->get('search', '');

        // Ler as últimas N linhas de forma eficiente
        $content = $this->tailFile($logPath, max($lines * 5, 1000));

        // Parsear entradas do log Laravel
        $entries = $this->parseLaravelLog($content);

        // Filtrar por level
        if ($level !== 'all') {
            $entries = array_values(array_filter($entries, fn($e) => strtolower($e['level']) === strtolower($level)));
        }

        // Filtrar por busca
        if (!empty($search)) {
            $searchLower = mb_strtolower($search);
            $entries = array_values(array_filter($entries, fn($e) =>
                str_contains(mb_strtolower($e['message']), $searchLower) ||
                str_contains(mb_strtolower($e['context'] ?? ''), $searchLower)
            ));
        }

        // Limitar e ordenar (mais recentes primeiro)
        $entries = array_slice($entries, -$lines);
        $entries = array_reverse($entries);

        return response()->json([
            'entries' => $entries,
            'total' => count($entries),
            'file_size' => $fileSize,
            'file_size_human' => $this->humanFileSize($fileSize),
        ]);
    }

    /**
     * Limpar o laravel.log
     */
    public function clearLaravelLog(): JsonResponse
    {
        $logPath = storage_path('logs/laravel.log');

        if (File::exists($logPath)) {
            File::put($logPath, '');
        }

        return response()->json(['success' => true, 'message' => 'Laravel log limpo com sucesso.']);
    }

    /**
     * Download do laravel.log
     */
    public function downloadLaravelLog()
    {
        $logPath = storage_path('logs/laravel.log');

        if (!File::exists($logPath)) {
            abort(404, 'Log file not found.');
        }

        return response()->download($logPath, 'laravel-' . now()->format('Y-m-d_H-i') . '.log');
    }

    // ===== PRIVATE HELPERS =====

    /**
     * Lê as últimas N linhas de um arquivo de forma eficiente
     */
    private function tailFile(string $path, int $lines = 1000): string
    {
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $startLine = max(0, $totalLines - $lines);
        $file->seek($startLine);

        $content = '';
        while (!$file->eof()) {
            $content .= $file->fgets();
        }

        return $content;
    }

    /**
     * Parseia o conteúdo do laravel.log em entradas estruturadas
     */
    private function parseLaravelLog(string $content): array
    {
        $entries = [];
        $lines = explode("\n", $content);
        $currentEntry = null;

        $pattern = '/^\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]\s+(\w+)\.(\w+):\s(.*)$/';

        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $matches)) {
                // Salvar entrada anterior
                if ($currentEntry) {
                    $entries[] = $currentEntry;
                }

                $currentEntry = [
                    'datetime' => $matches[1],
                    'environment' => $matches[2],
                    'level' => strtolower($matches[3]),
                    'message' => $matches[4],
                    'context' => '',
                    'stacktrace' => '',
                ];
            } elseif ($currentEntry && $line !== '') {
                // Linha de continuação (stacktrace ou contexto JSON)
                if (str_starts_with(trim($line), '#') || str_starts_with(trim($line), 'at ')) {
                    $currentEntry['stacktrace'] .= $line . "\n";
                } else {
                    $currentEntry['context'] .= $line . "\n";
                }
            }
        }

        // Adicionar última entrada
        if ($currentEntry) {
            $entries[] = $currentEntry;
        }

        // Limpar contexto e extrair JSON se possível
        foreach ($entries as &$entry) {
            $entry['context'] = trim($entry['context']);
            $entry['stacktrace'] = trim($entry['stacktrace']);

            // Tentar extrair JSON do contexto/mensagem
            if (preg_match('/(\{.*\})\s*$/s', $entry['message'], $jsonMatch)) {
                $decoded = json_decode($jsonMatch[1], true);
                if ($decoded !== null) {
                    $entry['message'] = trim(str_replace($jsonMatch[1], '', $entry['message']));
                    $entry['context_json'] = $decoded;
                }
            }

            // Limitar stacktrace para não ficar gigante
            if (mb_strlen($entry['stacktrace']) > 3000) {
                $entry['stacktrace'] = mb_substr($entry['stacktrace'], 0, 3000) . "\n... (truncado)";
            }
        }
        unset($entry);

        return $entries;
    }

    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 1) . ' ' . $units[$i];
    }
}

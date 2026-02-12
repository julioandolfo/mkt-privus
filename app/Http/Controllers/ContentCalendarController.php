<?php

namespace App\Http\Controllers;

use App\Models\ContentCalendarItem;
use App\Models\SystemLog;
use App\Services\Social\ContentCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentCalendarController extends Controller
{
    public function __construct(
        private ContentCalendarService $calendarService
    ) {}

    /**
     * Retorna itens do calendário de conteúdo para o período.
     */
    public function items(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand) {
            return response()->json(['items' => []]);
        }

        $start = $request->get('start', now()->startOfMonth()->format('Y-m-d'));
        $end = $request->get('end', now()->endOfMonth()->format('Y-m-d'));

        $items = ContentCalendarItem::where('brand_id', $brand->id)
            ->whereBetween('scheduled_date', [$start, $end])
            ->with('post:id,status')
            ->orderBy('scheduled_date')
            ->get()
            ->map(fn($item) => [
                'id' => $item->id,
                'date' => $item->scheduled_date->format('Y-m-d'),
                'title' => $item->title,
                'description' => $item->description,
                'category' => $item->category,
                'category_label' => $item->categoryLabel(),
                'platforms' => $item->platforms ?? [],
                'post_type' => $item->post_type,
                'tone' => $item->tone,
                'instructions' => $item->instructions,
                'status' => $item->status,
                'status_label' => $item->statusLabel(),
                'status_color' => $item->statusColor(),
                'post_id' => $item->post_id,
                'suggestion_id' => $item->suggestion_id,
                'batch_id' => $item->batch_id,
                'batch_status' => $item->batch_status,
            ]);

        // Contar drafts pendentes para banner de aprovacao
        $draftBatches = ContentCalendarItem::where('brand_id', $brand->id)
            ->where('batch_status', 'draft')
            ->selectRaw('batch_id, COUNT(*) as total, MIN(scheduled_date) as start_date, MAX(scheduled_date) as end_date')
            ->groupBy('batch_id')
            ->get()
            ->map(fn($b) => [
                'batch_id' => $b->batch_id,
                'total' => $b->total,
                'start_date' => $b->start_date,
                'end_date' => $b->end_date,
            ]);

        return response()->json([
            'items' => $items,
            'draft_batches' => $draftBatches,
        ]);
    }

    /**
     * Gera calendário de conteúdo com IA.
     */
    public function generate(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand) {
            return response()->json(['error' => 'Nenhuma marca ativa selecionada.'], 400);
        }

        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'posts_per_week' => 'nullable|integer|min:1|max:14',
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|in:instagram,facebook,linkedin,tiktok,youtube,pinterest',
            'categories' => 'nullable|array',
            'categories.*' => 'string',
            'tone' => 'nullable|string|max:100',
            'ai_model' => 'nullable|string',
            'instructions' => 'nullable|string|max:2000',
            'format_mode' => 'nullable|string|in:auto,manual',
            'post_types' => 'nullable|array',
            'post_types.*' => 'string|in:feed,carousel,story,reel,video,pin',
        ]);

        $result = $this->calendarService->generateCalendar(
            brand: $brand,
            userId: $request->user()->id,
            startDate: $validated['start_date'],
            endDate: $validated['end_date'],
            options: $validated,
        );

        if ($result['success']) {
            return response()->json([
                'message' => "{$result['total']} pautas geradas com sucesso!",
                'total' => $result['total'],
                'tokens_used' => $result['tokens_used'] ?? 0,
            ]);
        }

        return response()->json(['error' => $result['error'] ?? 'Erro ao gerar calendario.'], 422);
    }

    /**
     * Gera post a partir de um item do calendário (pauta).
     */
    public function generatePost(Request $request, ContentCalendarItem $item): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand || $item->brand_id !== $brand->id) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        if ($item->status !== 'pending') {
            return response()->json(['error' => 'Esta pauta ja foi processada.'], 422);
        }

        $suggestion = $this->calendarService->generatePostFromItem($item);

        if ($suggestion) {
            return response()->json([
                'message' => 'Post gerado com sucesso! Verifique em sugestoes para aprovar.',
                'suggestion_id' => $suggestion->id,
                'caption_preview' => mb_substr($suggestion->caption, 0, 120) . '...',
            ]);
        }

        return response()->json(['error' => 'Erro ao gerar post. Tente novamente.'], 500);
    }

    /**
     * Gera posts para todas as pautas pendentes do período.
     */
    public function generateAllPosts(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand) {
            return response()->json(['error' => 'Nenhuma marca ativa selecionada.'], 400);
        }

        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $limit = $request->integer('limit', 10);

        $result = $this->calendarService->generatePostsForPendingItems(
            $brand->id, $startDate, $endDate, min($limit, 20)
        );

        return response()->json([
            'message' => "{$result['generated']} posts gerados de {$result['total']} pautas.",
            'generated' => $result['generated'],
            'errors' => $result['errors'],
            'total' => $result['total'],
        ]);
    }

    /**
     * Atualiza um item do calendário.
     */
    public function update(Request $request, ContentCalendarItem $item): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand || $item->brand_id !== $brand->id) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $validated = $request->validate([
            'scheduled_date' => 'nullable|date',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:50',
            'platforms' => 'nullable|array',
            'post_type' => 'nullable|string|max:30',
            'tone' => 'nullable|string|max:100',
            'instructions' => 'nullable|string',
            'status' => 'nullable|string|in:pending,skipped',
        ]);

        $item->update(array_filter($validated, fn($v) => $v !== null));

        return response()->json(['message' => 'Pauta atualizada.', 'item' => $item->fresh()]);
    }

    /**
     * Remove um item do calendário.
     */
    public function destroy(Request $request, ContentCalendarItem $item): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand || $item->brand_id !== $brand->id) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $item->delete();

        return response()->json(['message' => 'Pauta removida.']);
    }

    /**
     * Remove todos os itens pendentes de um período.
     */
    public function clearPeriod(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand) {
            return response()->json(['error' => 'Nenhuma marca ativa.'], 400);
        }

        $start = $request->get('start_date');
        $end = $request->get('end_date');

        $deleted = ContentCalendarItem::where('brand_id', $brand->id)
            ->where('status', 'pending')
            ->when($start, fn($q) => $q->where('scheduled_date', '>=', $start))
            ->when($end, fn($q) => $q->where('scheduled_date', '<=', $end))
            ->delete();

        return response()->json(['message' => "{$deleted} pautas pendentes removidas.", 'deleted' => $deleted]);
    }

    /**
     * Aprova todas as pautas de um batch (draft -> approved).
     */
    public function approveBatch(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand) {
            return response()->json(['error' => 'Nenhuma marca ativa.'], 400);
        }

        $batchId = $request->get('batch_id');
        if (!$batchId) {
            return response()->json(['error' => 'batch_id obrigatorio.'], 422);
        }

        $updated = ContentCalendarItem::where('brand_id', $brand->id)
            ->where('batch_id', $batchId)
            ->where('batch_status', 'draft')
            ->update(['batch_status' => 'approved']);

        SystemLog::info('content', 'calendar.batch_approved', "Batch {$batchId} aprovado: {$updated} pautas", [
            'brand_id' => $brand->id,
            'batch_id' => $batchId,
            'approved_count' => $updated,
        ]);

        return response()->json([
            'message' => "{$updated} pautas aprovadas com sucesso!",
            'approved' => $updated,
        ]);
    }

    /**
     * Rejeita (remove) todas as pautas de um batch.
     */
    public function rejectBatch(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand) {
            return response()->json(['error' => 'Nenhuma marca ativa.'], 400);
        }

        $batchId = $request->get('batch_id');
        if (!$batchId) {
            return response()->json(['error' => 'batch_id obrigatorio.'], 422);
        }

        $deleted = ContentCalendarItem::where('brand_id', $brand->id)
            ->where('batch_id', $batchId)
            ->where('batch_status', 'draft')
            ->delete();

        SystemLog::info('content', 'calendar.batch_rejected', "Batch {$batchId} rejeitado: {$deleted} pautas removidas", [
            'brand_id' => $brand->id,
            'batch_id' => $batchId,
            'deleted_count' => $deleted,
        ]);

        return response()->json([
            'message' => "{$deleted} pautas rejeitadas e removidas.",
            'deleted' => $deleted,
        ]);
    }

    /**
     * Aprova uma pauta individual (draft -> approved).
     */
    public function approveItem(Request $request, ContentCalendarItem $item): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand || $item->brand_id !== $brand->id) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        if ($item->batch_status !== 'draft') {
            return response()->json(['error' => 'Esta pauta nao esta em rascunho.'], 422);
        }

        $item->update(['batch_status' => 'approved']);

        return response()->json(['message' => 'Pauta aprovada.']);
    }
}

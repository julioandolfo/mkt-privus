<?php

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Models\ContentSuggestion;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Social\ContentEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ContentSuggestionController extends Controller
{
    /**
     * Dashboard do Content Engine - sugestoes pendentes + stats
     */
    public function index(Request $request): Response
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return Inertia::render('Social/ContentEngine/Index', [
                'stats' => $this->emptyStats(),
                'suggestions' => [],
                'recentApproved' => [],
            ]);
        }

        // Stats
        $stats = [
            'pending' => $brand->contentSuggestions()->pending()->count(),
            'approved_today' => $brand->contentSuggestions()
                ->where('status', 'approved')
                ->whereDate('updated_at', today())
                ->count(),
            'converted_total' => $brand->contentSuggestions()->converted()->count(),
            'rejected_total' => $brand->contentSuggestions()->rejected()->count(),
            'rules_active' => $brand->contentRules()->active()->count(),
        ];

        // Sugestoes pendentes
        $suggestions = $brand->contentSuggestions()
            ->pending()
            ->with('contentRule:id,name,category')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(fn($s) => $this->formatSuggestion($s));

        // Recentes aprovadas/convertidas
        $recentApproved = $brand->contentSuggestions()
            ->whereIn('status', ['approved', 'converted'])
            ->with('contentRule:id,name')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(fn($s) => $this->formatSuggestion($s));

        return Inertia::render('Social/ContentEngine/Index', [
            'stats' => $stats,
            'suggestions' => $suggestions,
            'recentApproved' => $recentApproved,
        ]);
    }

    /**
     * Aprovar sugestao e converter em Post
     */
    public function approve(Request $request, ContentSuggestion $suggestion): RedirectResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand || $suggestion->brand_id !== $brand->id) {
            abort(403);
        }

        $scheduleNow = $request->boolean('schedule_now', false);

        // Criar Post real a partir da sugestao
        $post = Post::create([
            'brand_id' => $brand->id,
            'user_id' => $request->user()->id,
            'title' => $suggestion->title,
            'caption' => $suggestion->caption,
            'hashtags' => $suggestion->hashtags,
            'platforms' => $suggestion->platforms,
            'type' => $suggestion->post_type,
            'status' => $scheduleNow ? PostStatus::Scheduled : PostStatus::Draft,
        ]);

        // Copiar imagem gerada pela IA (se existir) para PostMedia
        $this->attachGeneratedImage($post, $suggestion);

        $suggestion->update([
            'status' => 'converted',
            'metadata' => array_merge($suggestion->metadata ?? [], [
                'converted_to_post_id' => $post->id,
                'converted_at' => now()->toISOString(),
                'converted_by' => $request->user()->id,
            ]),
        ]);

        return redirect()->back()->with('success', 'Sugestão aprovada e convertida em post!');
    }

    /**
     * Rejeitar sugestao
     */
    public function reject(Request $request, ContentSuggestion $suggestion): RedirectResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand || $suggestion->brand_id !== $brand->id) {
            abort(403);
        }

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $suggestion->update([
            'status' => 'rejected',
            'rejection_reason' => $request->input('reason'),
        ]);

        return redirect()->back()->with('success', 'Sugestão rejeitada.');
    }

    /**
     * Atualizar sugestao (editar antes de aprovar)
     */
    public function update(Request $request, ContentSuggestion $suggestion): RedirectResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand || $suggestion->brand_id !== $brand->id) {
            abort(403);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'caption' => 'required|string',
            'hashtags' => 'nullable|array',
            'platforms' => 'nullable|array',
            'post_type' => 'nullable|string|max:50',
        ]);

        $suggestion->update($validated);

        return redirect()->back()->with('success', 'Sugestão atualizada!');
    }

    /**
     * Aprovar multiplas de uma vez
     */
    public function bulkApprove(Request $request): RedirectResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return redirect()->back()->withErrors(['brand' => 'Selecione uma marca.']);
        }

        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:content_suggestions,id',
        ]);

        $count = 0;
        foreach ($request->input('ids') as $id) {
            $suggestion = ContentSuggestion::find($id);
            if ($suggestion && $suggestion->brand_id === $brand->id && $suggestion->isPending()) {
                $post = Post::create([
                    'brand_id' => $brand->id,
                    'user_id' => $request->user()->id,
                    'title' => $suggestion->title,
                    'caption' => $suggestion->caption,
                    'hashtags' => $suggestion->hashtags,
                    'platforms' => $suggestion->platforms,
                    'type' => $suggestion->post_type,
                    'status' => PostStatus::Draft,
                ]);

                // Copiar imagem gerada pela IA (se existir)
                $this->attachGeneratedImage($post, $suggestion);

                $suggestion->update([
                    'status' => 'converted',
                    'metadata' => array_merge($suggestion->metadata ?? [], [
                        'converted_to_post_id' => $post->id,
                        'converted_at' => now()->toISOString(),
                    ]),
                ]);

                $count++;
            }
        }

        return redirect()->back()->with('success', "{$count} sugestões aprovadas e convertidas em posts!");
    }

    /**
     * Gerar sugestões inteligentes manualmente
     */
    public function generateSmart(Request $request, ContentEngineService $engine): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json(['success' => false, 'message' => 'Selecione uma marca.'], 400);
        }

        $count = $request->input('count', 3);
        $suggestions = $engine->generateSmartSuggestions($brand, min($count, 5));

        return response()->json([
            'success' => true,
            'count' => count($suggestions),
            'message' => count($suggestions) . ' sugestões geradas com sucesso!',
        ]);
    }

    // ===== PRIVATE =====

    /**
     * Anexa imagem gerada pela IA (armazenada no metadata da sugestão) como PostMedia.
     */
    private function attachGeneratedImage(Post $post, ContentSuggestion $suggestion): void
    {
        $metadata = $suggestion->metadata ?? [];
        $imageInfo = $metadata['generated_image'] ?? null;

        if (!$imageInfo || empty($imageInfo['path'])) {
            return;
        }

        // Verificar se o arquivo existe no storage
        if (!Storage::disk('public')->exists($imageInfo['path'])) {
            return;
        }

        $fileSize = Storage::disk('public')->size($imageInfo['path']);

        // Determinar dimensões a partir do size (ex: "1024x1024")
        $dimensions = explode('x', $imageInfo['size'] ?? '1024x1024');
        $width = (int) ($dimensions[0] ?? 1024);
        $height = (int) ($dimensions[1] ?? 1024);

        PostMedia::create([
            'post_id' => $post->id,
            'type' => 'image',
            'file_path' => $imageInfo['path'],
            'file_name' => basename($imageInfo['path']),
            'mime_type' => 'image/png',
            'file_size' => $fileSize,
            'width' => $width,
            'height' => $height,
            'order' => 0,
            'alt_text' => $suggestion->title,
            'metadata' => [
                'source' => 'ai_generated',
                'model' => $imageInfo['model'] ?? 'dall-e-3',
                'prompt' => $imageInfo['prompt'] ?? null,
            ],
        ]);
    }

    private function formatSuggestion(ContentSuggestion $suggestion): array
    {
        $metadata = $suggestion->metadata ?? [];
        $generatedImage = $metadata['generated_image'] ?? null;

        return [
            'id' => $suggestion->id,
            'title' => $suggestion->title,
            'caption' => $suggestion->caption,
            'caption_preview' => mb_substr($suggestion->caption, 0, 120) . (mb_strlen($suggestion->caption) > 120 ? '...' : ''),
            'hashtags' => $suggestion->hashtags ?? [],
            'platforms' => $suggestion->platforms ?? [],
            'post_type' => $suggestion->post_type,
            'status' => $suggestion->status,
            'status_label' => $suggestion->statusLabel(),
            'status_color' => $suggestion->statusColor(),
            'ai_model_used' => $suggestion->ai_model_used,
            'tokens_used' => $suggestion->tokens_used,
            'rule_name' => $suggestion->contentRule?->name,
            'rule_category' => $suggestion->contentRule?->category,
            'is_from_rule' => $suggestion->isFromRule(),
            'rejection_reason' => $suggestion->rejection_reason,
            'has_generated_image' => $generatedImage !== null,
            'generated_image_url' => $generatedImage ? ($generatedImage['url'] ?? null) : null,
            'created_at' => $suggestion->created_at->format('d/m/Y H:i'),
            'metadata' => $suggestion->metadata,
        ];
    }

    private function emptyStats(): array
    {
        return [
            'pending' => 0,
            'approved_today' => 0,
            'converted_total' => 0,
            'rejected_total' => 0,
            'rules_active' => 0,
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\AIModel;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\SocialPlatform;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Social\ContentGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function __construct(
        private readonly ContentGeneratorService $contentGenerator,
    ) {}

    /**
     * Lista de posts da marca ativa
     */
    public function index(Request $request): Response
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return Inertia::render('Social/Posts/Index', [
                'posts' => [],
                'filters' => [],
                'stats' => ['drafts' => 0, 'scheduled' => 0, 'published' => 0, 'failed' => 0],
                'platforms' => $this->getPlatformOptions(),
                'statuses' => $this->getStatusOptions(),
            ]);
        }

        $query = Post::with(['media', 'user'])
            ->forBrand($brand->id)
            ->latest();

        // Filtros
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('platform')) {
            $query->whereJsonContains('platforms', $request->input('platform'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('caption', 'like', "%{$search}%");
            });
        }

        $posts = $query->paginate(12)->through(fn($post) => [
            'id' => $post->id,
            'title' => $post->title,
            'caption' => $post->caption,
            'hashtags' => $post->hashtags,
            'type' => $post->type?->value,
            'type_label' => $post->type?->label(),
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'status_color' => $post->status->color(),
            'platforms' => $post->platforms ?? [],
            'scheduled_at' => $post->scheduled_at?->format('d/m/Y H:i'),
            'published_at' => $post->published_at?->format('d/m/Y H:i'),
            'created_at' => $post->created_at->format('d/m/Y H:i'),
            'user_name' => $post->user?->name,
            'media' => $post->media->map(fn($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'file_path' => $m->file_path ? Storage::url($m->file_path) : null,
                'file_name' => $m->file_name,
                'alt_text' => $m->alt_text,
            ]),
        ]);

        // Estatisticas
        $stats = [
            'drafts' => Post::forBrand($brand->id)->drafts()->count(),
            'scheduled' => Post::forBrand($brand->id)->scheduled()->count(),
            'published' => Post::forBrand($brand->id)->published()->count(),
            'failed' => Post::forBrand($brand->id)->where('status', PostStatus::Failed)->count(),
        ];

        return Inertia::render('Social/Posts/Index', [
            'posts' => $posts,
            'filters' => $request->only(['status', 'platform', 'type', 'search']),
            'stats' => $stats,
            'platforms' => $this->getPlatformOptions(),
            'statuses' => $this->getStatusOptions(),
        ]);
    }

    /**
     * Formulario de criacao de post
     */
    public function create(Request $request): Response
    {
        $brand = $request->user()->getActiveBrand();
        $accounts = [];

        if ($brand) {
            $accounts = $brand->socialAccounts()
                ->where('is_active', true)
                ->get()
                ->map(fn($acc) => [
                    'id' => $acc->id,
                    'platform' => $acc->platform->value,
                    'platform_label' => $acc->platform->label(),
                    'platform_color' => $acc->platform->color(),
                    'username' => $acc->username,
                    'display_name' => $acc->display_name,
                ]);
        }

        return Inertia::render('Social/Posts/Create', [
            'platforms' => $this->getPlatformOptions(),
            'postTypes' => $this->getPostTypeOptions(),
            'accounts' => $accounts,
            'aiModels' => $this->getAIModelOptions(),
        ]);
    }

    /**
     * Salvar novo post
     */
    public function store(Request $request): RedirectResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return redirect()->back()->withErrors(['brand' => 'Selecione uma marca ativa.']);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'caption' => 'required|string|max:10000',
            'hashtags' => 'nullable|array',
            'hashtags.*' => 'string|max:100',
            'type' => 'required|string',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string',
            'scheduled_at' => 'nullable|date|after:now',
            'media' => 'nullable|array|max:10',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,webp,mp4,mov,avi|max:51200',
        ]);

        $status = $validated['scheduled_at']
            ? PostStatus::Scheduled
            : PostStatus::Draft;

        $post = Post::create([
            'brand_id' => $brand->id,
            'user_id' => $request->user()->id,
            'title' => $validated['title'] ?? null,
            'caption' => $validated['caption'],
            'hashtags' => $validated['hashtags'] ?? [],
            'type' => $validated['type'],
            'status' => $status,
            'platforms' => $validated['platforms'],
            'scheduled_at' => $validated['scheduled_at'] ?? null,
        ]);

        // Upload de midias
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $index => $file) {
                $path = $file->store("posts/{$post->id}", 'public');
                $mimeType = $file->getMimeType();
                $isVideo = str_starts_with($mimeType, 'video/');

                PostMedia::create([
                    'post_id' => $post->id,
                    'type' => $isVideo ? 'video' : 'image',
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $mimeType,
                    'file_size' => $file->getSize(),
                    'order' => $index,
                ]);
            }
        }

        return redirect()->route('social.posts.index')
            ->with('success', 'Post criado com sucesso!');
    }

    /**
     * Formulario de edicao de post
     */
    public function edit(Request $request, Post $post): Response
    {
        $this->authorizePost($request, $post);

        $post->load('media');

        $brand = $request->user()->getActiveBrand();
        $accounts = [];

        if ($brand) {
            $accounts = $brand->socialAccounts()
                ->where('is_active', true)
                ->get()
                ->map(fn($acc) => [
                    'id' => $acc->id,
                    'platform' => $acc->platform->value,
                    'platform_label' => $acc->platform->label(),
                    'platform_color' => $acc->platform->color(),
                    'username' => $acc->username,
                    'display_name' => $acc->display_name,
                ]);
        }

        return Inertia::render('Social/Posts/Edit', [
            'post' => [
                'id' => $post->id,
                'title' => $post->title,
                'caption' => $post->caption,
                'hashtags' => $post->hashtags ?? [],
                'type' => $post->type?->value,
                'status' => $post->status->value,
                'platforms' => $post->platforms ?? [],
                'scheduled_at' => $post->scheduled_at?->format('Y-m-d\TH:i'),
                'ai_model_used' => $post->ai_model_used,
                'ai_prompt' => $post->ai_prompt,
                'media' => $post->media->map(fn($m) => [
                    'id' => $m->id,
                    'type' => $m->type,
                    'file_path' => $m->file_path ? Storage::url($m->file_path) : null,
                    'file_name' => $m->file_name,
                    'alt_text' => $m->alt_text,
                    'order' => $m->order,
                ]),
            ],
            'platforms' => $this->getPlatformOptions(),
            'postTypes' => $this->getPostTypeOptions(),
            'accounts' => $accounts,
            'aiModels' => $this->getAIModelOptions(),
        ]);
    }

    /**
     * Atualizar post existente
     */
    public function update(Request $request, Post $post): RedirectResponse
    {
        $this->authorizePost($request, $post);

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'caption' => 'required|string|max:10000',
            'hashtags' => 'nullable|array',
            'hashtags.*' => 'string|max:100',
            'type' => 'required|string',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string',
            'scheduled_at' => 'nullable|date',
            'status' => 'nullable|string',
            'media' => 'nullable|array|max:10',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,webp,mp4,mov,avi|max:51200',
            'remove_media' => 'nullable|array',
            'remove_media.*' => 'integer',
        ]);

        // Determinar status
        $status = $validated['status'] ?? $post->status->value;
        if (!$validated['status'] && $validated['scheduled_at'] && $post->status === PostStatus::Draft) {
            $status = PostStatus::Scheduled->value;
        }

        $post->update([
            'title' => $validated['title'] ?? null,
            'caption' => $validated['caption'],
            'hashtags' => $validated['hashtags'] ?? [],
            'type' => $validated['type'],
            'platforms' => $validated['platforms'],
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'status' => $status,
        ]);

        // Remover midias
        if (!empty($validated['remove_media'])) {
            $mediaToRemove = PostMedia::where('post_id', $post->id)
                ->whereIn('id', $validated['remove_media'])
                ->get();

            foreach ($mediaToRemove as $media) {
                if ($media->file_path) {
                    Storage::disk('public')->delete($media->file_path);
                }
                $media->delete();
            }
        }

        // Upload de novas midias
        if ($request->hasFile('media')) {
            $maxOrder = $post->media()->max('order') ?? -1;

            foreach ($request->file('media') as $index => $file) {
                $path = $file->store("posts/{$post->id}", 'public');
                $mimeType = $file->getMimeType();
                $isVideo = str_starts_with($mimeType, 'video/');

                PostMedia::create([
                    'post_id' => $post->id,
                    'type' => $isVideo ? 'video' : 'image',
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $mimeType,
                    'file_size' => $file->getSize(),
                    'order' => $maxOrder + $index + 1,
                ]);
            }
        }

        return redirect()->route('social.posts.index')
            ->with('success', 'Post atualizado com sucesso!');
    }

    /**
     * Excluir post
     */
    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $this->authorizePost($request, $post);

        // Remover arquivos de midia
        foreach ($post->media as $media) {
            if ($media->file_path) {
                Storage::disk('public')->delete($media->file_path);
            }
        }

        $post->delete();

        return redirect()->route('social.posts.index')
            ->with('success', 'Post removido com sucesso!');
    }

    /**
     * Gerar conteudo com IA (legenda e hashtags)
     */
    public function generateContent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'topic' => 'required|string|max:500',
            'platform' => 'required|string',
            'type' => 'nullable|string',
            'tone' => 'nullable|string|max:100',
            'instructions' => 'nullable|string|max:1000',
            'model' => 'nullable|string',
        ]);

        $brand = $request->user()->getActiveBrand();
        $platform = SocialPlatform::from($validated['platform']);
        $postType = isset($validated['type']) ? PostType::from($validated['type']) : PostType::Feed;
        $aiModel = isset($validated['model']) ? AIModel::from($validated['model']) : AIModel::GPT4oMini;

        try {
            $result = $this->contentGenerator->generateCaption(
                brand: $brand,
                user: $request->user(),
                platform: $platform,
                postType: $postType,
                topic: $validated['topic'],
                tone: $validated['tone'] ?? null,
                instructions: $validated['instructions'] ?? null,
                model: $aiModel,
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao gerar conteúdo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pagina do calendario visual
     */
    public function calendar(): Response
    {
        return Inertia::render('Social/Calendar/Index');
    }

    /**
     * Dados para o calendario de posts (JSON)
     */
    public function calendarData(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json(['posts' => []]);
        }

        $start = $request->input('start', now()->startOfMonth()->toDateString());
        $end = $request->input('end', now()->endOfMonth()->toDateString());

        $posts = Post::with('media')
            ->forBrand($brand->id)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('scheduled_at', [$start, $end])
                  ->orWhereBetween('published_at', [$start, $end])
                  ->orWhereBetween('created_at', [$start, $end]);
            })
            ->get()
            ->map(fn($post) => [
                'id' => $post->id,
                'title' => $post->title ?: mb_substr($post->caption, 0, 40) . '...',
                'date' => ($post->scheduled_at ?? $post->published_at ?? $post->created_at)->format('Y-m-d'),
                'time' => ($post->scheduled_at ?? $post->published_at ?? $post->created_at)->format('H:i'),
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'status_color' => $post->status->color(),
                'platforms' => $post->platforms ?? [],
                'type' => $post->type?->value,
                'has_media' => $post->media->isNotEmpty(),
            ]);

        return response()->json(['posts' => $posts]);
    }

    /**
     * Duplicar post existente
     */
    public function duplicate(Request $request, Post $post): RedirectResponse
    {
        $this->authorizePost($request, $post);

        $newPost = $post->replicate();
        $newPost->title = ($post->title ?? 'Post') . ' (cópia)';
        $newPost->status = PostStatus::Draft;
        $newPost->scheduled_at = null;
        $newPost->published_at = null;
        $newPost->save();

        // Duplicar midias
        foreach ($post->media as $media) {
            $newMedia = $media->replicate();
            $newMedia->post_id = $newPost->id;

            // Copiar arquivo
            if ($media->file_path && Storage::disk('public')->exists($media->file_path)) {
                $newPath = str_replace("posts/{$post->id}", "posts/{$newPost->id}", $media->file_path);
                Storage::disk('public')->copy($media->file_path, $newPath);
                $newMedia->file_path = $newPath;
            }

            $newMedia->save();
        }

        return redirect()->route('social.posts.edit', $newPost)
            ->with('success', 'Post duplicado! Edite a cópia.');
    }

    /**
     * Reagenda (move) um post para outra data via drag-and-drop no calendario.
     */
    public function reschedule(Request $request, Post $post): JsonResponse
    {
        $this->authorizePost($request, $post);

        // Nao permitir mover posts ja publicados
        if ($post->status === PostStatus::Published) {
            return response()->json(['error' => 'Posts ja publicados nao podem ser movidos.'], 422);
        }

        $validated = $request->validate([
            'date' => 'required|date',
        ]);

        // Manter o horario original, so alterar a data
        $oldDate = $post->scheduled_at ?? $post->created_at;
        $newDate = \Carbon\Carbon::parse($validated['date'])->setTime(
            $oldDate->hour,
            $oldDate->minute,
            $oldDate->second
        );

        $post->update([
            'scheduled_at' => $newDate,
            'status' => $post->status === PostStatus::Draft ? PostStatus::Scheduled->value : $post->status->value,
        ]);

        return response()->json([
            'message' => 'Post reagendado para ' . $newDate->format('d/m/Y') . '.',
            'new_date' => $newDate->format('Y-m-d'),
        ]);
    }

    // ===== PRIVATE METHODS =====

    private function authorizePost(Request $request, Post $post): void
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand || $post->brand_id !== $brand->id) {
            abort(403, 'Acesso negado.');
        }
    }

    private function getPlatformOptions(): array
    {
        return collect(SocialPlatform::cases())->map(fn($p) => [
            'value' => $p->value,
            'label' => $p->label(),
            'color' => $p->color(),
        ])->toArray();
    }

    private function getStatusOptions(): array
    {
        return collect(PostStatus::cases())->map(fn($s) => [
            'value' => $s->value,
            'label' => $s->label(),
            'color' => $s->color(),
        ])->toArray();
    }

    private function getPostTypeOptions(): array
    {
        return collect(PostType::cases())->map(fn($t) => [
            'value' => $t->value,
            'label' => $t->label(),
            'dimensions' => $t->dimensions(),
        ])->toArray();
    }

    private function getAIModelOptions(): array
    {
        return collect(AIModel::cases())->map(fn($m) => [
            'value' => $m->value,
            'label' => $m->label(),
            'provider' => $m->provider()->label(),
        ])->toArray();
    }
}

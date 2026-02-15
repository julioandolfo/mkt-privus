<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsConnection;
use App\Models\BlogArticle;
use App\Models\BlogCalendarItem;
use App\Models\BlogCategory;
use App\Models\SystemLog;
use App\Services\Blog\BlogArticleService;
use App\Services\Blog\BlogCalendarService;
use App\Services\Blog\WordPressPublishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class BlogController extends Controller
{
    public function __construct(
        private BlogArticleService $articleService,
        private WordPressPublishService $wpService,
        private BlogCalendarService $calendarService,
    ) {}

    /**
     * Lista de artigos
     */
    public function index(Request $request): Response
    {
        $brandId = session('current_brand_id');

        $articles = BlogArticle::forBrand($brandId)
            ->with(['category:id,name', 'wordpressConnection:id,name,platform', 'user:id,name'])
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('category'), fn($q, $c) => $q->where('blog_category_id', $c))
            ->when($request->input('connection'), fn($q, $c) => $q->where('wordpress_connection_id', $c))
            ->when($request->input('search'), fn($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->latest()
            ->paginate(15)
            ->through(fn($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'slug' => $a->slug,
                'excerpt' => $a->excerpt,
                'cover_image_path' => $a->cover_image_path,
                'status' => $a->status,
                'status_label' => $a->status_label,
                'status_color' => $a->status_color,
                'category' => $a->category?->name,
                'connection_name' => $a->wordpressConnection?->name,
                'connection_platform' => $a->wordpressConnection?->platform,
                'wp_post_url' => $a->wp_post_url,
                'word_count' => $a->word_count,
                'reading_time' => $a->reading_time,
                'seo_score' => $a->seoScore(),
                'published_at' => $a->published_at?->format('d/m/Y H:i'),
                'scheduled_publish_at' => $a->scheduled_publish_at?->format('d/m/Y H:i'),
                'created_at' => $a->created_at->format('d/m/Y'),
                'user_name' => $a->user?->name,
                'can_approve' => $a->canApprove(),
                'can_publish' => $a->canPublish(),
                'has_wordpress' => (bool) $a->wordpress_connection_id,
            ]);

        // Métricas resumidas
        $stats = [
            'total' => BlogArticle::forBrand($brandId)->count(),
            'published' => BlogArticle::forBrand($brandId)->published()->count(),
            'pending' => BlogArticle::forBrand($brandId)->pendingReview()->count(),
            'draft' => BlogArticle::forBrand($brandId)->draft()->count(),
        ];

        $categories = BlogCategory::forBrand($brandId)->orderBy('name')->get(['id', 'name']);
        $connections = $this->getWordPressConnections($brandId);

        return Inertia::render('Blog/Index', [
            'articles' => $articles,
            'stats' => $stats,
            'categories' => $categories,
            'connections' => $connections,
            'filters' => $request->only('status', 'category', 'connection', 'search'),
        ]);
    }

    /**
     * Formulário de criação
     */
    public function create(Request $request): Response
    {
        $brandId = session('current_brand_id');

        $categories = BlogCategory::forBrand($brandId)->orderBy('name')->get(['id', 'name', 'wordpress_connection_id']);
        $connections = $this->getWordPressConnections($brandId);

        return Inertia::render('Blog/Create', [
            'categories' => $categories,
            'connections' => $connections,
        ]);
    }

    /**
     * Salvar artigo
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'excerpt' => 'nullable|string|max:500',
            'cover_image_path' => 'nullable|string|max:500',
            'blog_category_id' => 'nullable|exists:blog_categories,id',
            'wordpress_connection_id' => 'nullable|exists:analytics_connections,id',
            'tags' => 'nullable|array',
            'meta_title' => 'nullable|string|max:100',
            'meta_description' => 'nullable|string|max:300',
            'meta_keywords' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:draft,pending_review,approved',
            'scheduled_publish_at' => 'nullable|date|after:now',
            'ai_model_used' => 'nullable|string',
            'tokens_used' => 'nullable|integer',
            'ai_metadata' => 'nullable|array',
        ]);

        $brandId = session('current_brand_id');

        $article = BlogArticle::create([
            ...$validated,
            'brand_id' => $brandId,
            'user_id' => Auth::id(),
            'slug' => BlogArticle::generateUniqueSlug($validated['title']),
            'status' => $validated['status'] ?? 'draft',
        ]);

        return redirect()->route('blog.edit', $article)
            ->with('success', 'Artigo criado com sucesso!');
    }

    /**
     * Visualizar artigo
     */
    public function show(BlogArticle $article): Response
    {
        $article->load(['category:id,name', 'wordpressConnection:id,name,platform', 'user:id,name']);

        return Inertia::render('Blog/Show', [
            'article' => [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug,
                'excerpt' => $article->excerpt,
                'content' => $article->content,
                'cover_image_path' => $article->cover_image_path,
                'status' => $article->status,
                'status_label' => $article->status_label,
                'status_color' => $article->status_color,
                'category' => $article->category?->name,
                'connection_name' => $article->wordpressConnection?->name,
                'tags' => $article->tags,
                'meta_title' => $article->meta_title,
                'meta_description' => $article->meta_description,
                'meta_keywords' => $article->meta_keywords,
                'wp_post_id' => $article->wp_post_id,
                'wp_post_url' => $article->wp_post_url,
                'word_count' => $article->word_count,
                'reading_time' => $article->reading_time,
                'seo_score' => $article->seoScore(),
                'published_at' => $article->published_at?->format('d/m/Y H:i'),
                'created_at' => $article->created_at->format('d/m/Y H:i'),
                'user_name' => $article->user?->name,
                'ai_model_used' => $article->ai_model_used,
                'tokens_used' => $article->tokens_used,
                'can_approve' => $article->canApprove(),
                'can_publish' => $article->canPublish(),
                'can_edit' => $article->canEdit(),
                'has_wordpress' => (bool) $article->wordpress_connection_id,
            ],
        ]);
    }

    /**
     * Editor de artigo
     */
    public function edit(BlogArticle $article): Response
    {
        $brandId = session('current_brand_id');
        $categories = BlogCategory::forBrand($brandId)->orderBy('name')->get(['id', 'name', 'wordpress_connection_id']);
        $connections = $this->getWordPressConnections($brandId);

        return Inertia::render('Blog/Edit', [
            'article' => [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug,
                'excerpt' => $article->excerpt,
                'content' => $article->content,
                'cover_image_path' => $article->cover_image_path,
                'status' => $article->status,
                'status_label' => $article->status_label,
                'blog_category_id' => $article->blog_category_id,
                'wordpress_connection_id' => $article->wordpress_connection_id,
                'tags' => $article->tags ?? [],
                'meta_title' => $article->meta_title,
                'meta_description' => $article->meta_description,
                'meta_keywords' => $article->meta_keywords,
                'wp_post_id' => $article->wp_post_id,
                'wp_post_url' => $article->wp_post_url,
                'scheduled_publish_at' => $article->scheduled_publish_at?->format('Y-m-d\TH:i'),
                'word_count' => $article->word_count,
                'seo_score' => $article->seoScore(),
                'can_publish' => $article->canPublish(),
                'can_edit' => $article->canEdit(),
                'can_approve' => $article->canApprove(),
            ],
            'categories' => $categories,
            'connections' => $connections,
        ]);
    }

    /**
     * Atualizar artigo
     */
    public function update(Request $request, BlogArticle $article): RedirectResponse
    {
        if (!$article->canEdit()) {
            return redirect()->back()->with('error', 'Este artigo não pode ser editado no status atual.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'excerpt' => 'nullable|string|max:500',
            'cover_image_path' => 'nullable|string|max:500',
            'blog_category_id' => 'nullable|exists:blog_categories,id',
            'wordpress_connection_id' => 'nullable|exists:analytics_connections,id',
            'tags' => 'nullable|array',
            'meta_title' => 'nullable|string|max:100',
            'meta_description' => 'nullable|string|max:300',
            'meta_keywords' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:draft,pending_review,approved',
            'scheduled_publish_at' => 'nullable|date',
        ]);

        // Regenerar slug se título mudou
        if ($validated['title'] !== $article->title) {
            $validated['slug'] = BlogArticle::generateUniqueSlug($validated['title'], $article->id);
        }

        $article->update($validated);

        return redirect()->back()->with('success', 'Artigo atualizado!');
    }

    /**
     * Excluir artigo
     */
    public function destroy(BlogArticle $article): RedirectResponse
    {
        $article->delete();
        return redirect()->route('blog.index')->with('success', 'Artigo movido para lixeira.');
    }

    /**
     * Publicar artigo no WordPress
     */
    public function publish(BlogArticle $article): JsonResponse
    {
        if (!$article->canPublish()) {
            return response()->json(['success' => false, 'error' => 'Artigo não pode ser publicado. Verifique status e conexão.']);
        }

        $result = $this->wpService->publish($article);

        return response()->json($result);
    }

    /**
     * Aprovar artigo para publicação
     */
    public function approve(BlogArticle $article): RedirectResponse
    {
        if (!$article->canApprove()) {
            return redirect()->back()->with('error', 'Artigo não está aguardando revisão.');
        }

        $article->update(['status' => 'approved']);

        return redirect()->back()->with('success', 'Artigo aprovado! Agora pode ser publicado.');
    }

    /**
     * Gerar artigo com IA (AJAX)
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'topic' => 'required|string|max:500',
            'keywords' => 'nullable|string|max:500',
            'tone' => 'nullable|string|max:100',
            'instructions' => 'nullable|string|max:2000',
            'word_count' => 'nullable|integer|min:200|max:5000',
        ]);

        $brand = Auth::user()->getActiveBrand();
        if (!$brand) {
            return response()->json(['success' => false, 'error' => 'Nenhuma marca ativa selecionada.']);
        }

        $result = $this->articleService->generateArticle(
            brand: $brand,
            topic: $request->input('topic'),
            keywords: $request->input('keywords'),
            tone: $request->input('tone'),
            instructions: $request->input('instructions'),
            wordCount: $request->input('word_count', 800),
            user: Auth::user(),
        );

        return response()->json($result);
    }

    /**
     * Gerar imagem de capa com DALL-E 3 (AJAX)
     */
    public function generateCover(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'excerpt' => 'nullable|string|max:500',
            'cover_width' => 'nullable|integer|min:100|max:4000',
            'cover_height' => 'nullable|integer|min:100|max:4000',
        ]);

        $brand = Auth::user()->getActiveBrand();
        if (!$brand) {
            return response()->json(['success' => false, 'error' => 'Nenhuma marca ativa.']);
        }

        $result = $this->articleService->generateCoverImage(
            brand: $brand,
            title: $request->input('title'),
            excerpt: $request->input('excerpt', ''),
            width: $request->input('cover_width', 1750),
            height: $request->input('cover_height', 650),
        );

        if ($result) {
            return response()->json(['success' => true, ...$result]);
        }

        return response()->json(['success' => false, 'error' => 'Não foi possível gerar a imagem.']);
    }

    /**
     * Gerar sugestões de temas (AJAX)
     */
    public function generateTopics(Request $request): JsonResponse
    {
        $request->validate([
            'connection_id' => 'nullable|integer',
            'count' => 'nullable|integer|min:1|max:10',
        ]);

        $brand = Auth::user()->getActiveBrand();
        if (!$brand) {
            return response()->json(['success' => false, 'error' => 'Nenhuma marca ativa.']);
        }

        $connection = $request->input('connection_id')
            ? AnalyticsConnection::find($request->input('connection_id'))
            : null;

        $topics = $this->articleService->generateTopicSuggestions(
            brand: $brand,
            connection: $connection,
            count: $request->input('count', 5),
        );

        return response()->json(['success' => true, 'topics' => $topics]);
    }

    /**
     * Gerar SEO metadata com IA (AJAX)
     */
    public function generateSeo(BlogArticle $article): JsonResponse
    {
        $result = $this->articleService->generateSeoMetadata($article);
        return response()->json($result);
    }

    /**
     * Upload de imagem de capa
     */
    public function uploadCover(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|max:10240', // 10MB
        ]);

        try {
            $path = $request->file('image')->store('blog-covers', 'public');

            return response()->json([
                'success' => true,
                'path' => $path,
                'url' => \Illuminate\Support\Facades\Storage::disk('public')->url($path),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ===== CATEGORIAS =====

    /**
     * Listar categorias
     */
    public function categories(Request $request): Response
    {
        $brandId = session('current_brand_id');

        $categories = BlogCategory::forBrand($brandId)
            ->withCount('articles')
            ->orderBy('name')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'description' => $c->description,
                'articles_count' => $c->articles_count,
                'wp_category_id' => $c->wp_category_id,
                'wordpress_connection_id' => $c->wordpress_connection_id,
            ]);

        $connections = $this->getWordPressConnections($brandId);

        return Inertia::render('Blog/Categories', [
            'categories' => $categories,
            'connections' => $connections,
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'wordpress_connection_id' => 'nullable|exists:analytics_connections,id',
        ]);

        BlogCategory::create([
            ...$validated,
            'brand_id' => session('current_brand_id'),
            'slug' => \Illuminate\Support\Str::slug($validated['name']),
        ]);

        return redirect()->back()->with('success', 'Categoria criada!');
    }

    public function updateCategory(Request $request, BlogCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $category->update([
            ...$validated,
            'slug' => \Illuminate\Support\Str::slug($validated['name']),
        ]);

        return redirect()->back()->with('success', 'Categoria atualizada!');
    }

    public function destroyCategory(BlogCategory $category): RedirectResponse
    {
        $category->delete();
        return redirect()->back()->with('success', 'Categoria removida.');
    }

    /**
     * Sincronizar categorias do WordPress
     */
    public function syncCategories(Request $request): JsonResponse
    {
        $request->validate(['connection_id' => 'required|exists:analytics_connections,id']);

        $connection = AnalyticsConnection::findOrFail($request->input('connection_id'));
        $brandId = session('current_brand_id');

        $synced = $this->wpService->syncCategories($connection, $brandId);

        return response()->json(['success' => true, 'synced' => $synced, 'message' => "{$synced} categorias sincronizadas."]);
    }

    // ===== CONEXÕES WORDPRESS =====

    /**
     * Cadastrar nova conexão WordPress pura
     */
    public function storeConnection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'site_url' => 'required|url|max:500',
            'wp_username' => 'required|string|max:255',
            'wp_app_password' => 'required|string|max:500',
        ]);

        $brandId = session('current_brand_id');

        // Testar conexão antes de salvar
        $testConnection = new AnalyticsConnection([
            'config' => [
                'site_url' => rtrim($validated['site_url'], '/'),
                'wp_username' => $validated['wp_username'],
                'wp_app_password' => $validated['wp_app_password'],
            ],
        ]);

        $testResult = $this->wpService->testConnection($testConnection);

        if (!$testResult['success']) {
            return response()->json(['success' => false, 'error' => $testResult['error']]);
        }

        $connection = AnalyticsConnection::create([
            'brand_id' => $brandId,
            'user_id' => Auth::id(),
            'platform' => 'wordpress',
            'name' => $validated['name'],
            'external_name' => $testResult['site_name'] ?? $validated['name'],
            'config' => [
                'site_url' => rtrim($validated['site_url'], '/'),
                'wp_username' => $validated['wp_username'],
                'wp_app_password' => $validated['wp_app_password'],
            ],
            'is_active' => true,
            'sync_status' => 'success',
            'last_synced_at' => now(),
        ]);

        // Auto-sincronizar categorias do WordPress
        $syncedCategories = 0;
        try {
            $syncedCategories = $this->wpService->syncCategories($connection, $brandId);
        } catch (\Throwable $e) {
            // Nao bloquear criacao da conexao se sync falhar
            SystemLog::warning('blog', 'connection.category_sync_error', "Erro ao auto-sincronizar categorias: {$e->getMessage()}", [
                'connection_id' => $connection->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'connection' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'platform' => 'wordpress',
                'site_url' => rtrim($validated['site_url'], '/'),
            ],
            'synced_categories' => $syncedCategories,
            'message' => "Conexão WordPress criada com sucesso!" . ($syncedCategories > 0 ? " {$syncedCategories} categorias sincronizadas." : ''),
        ]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        $request->validate([
            'site_url' => 'required|url',
            'wp_username' => 'required|string',
            'wp_app_password' => 'required|string',
        ]);

        $testConnection = new AnalyticsConnection([
            'config' => [
                'site_url' => rtrim($request->input('site_url'), '/'),
                'wp_username' => $request->input('wp_username'),
                'wp_app_password' => $request->input('wp_app_password'),
            ],
        ]);

        $result = $this->wpService->testConnection($testConnection);

        return response()->json($result);
    }

    public function destroyConnection(AnalyticsConnection $connection): JsonResponse
    {
        if ($connection->platform !== 'wordpress') {
            return response()->json(['success' => false, 'error' => 'Apenas conexões WordPress podem ser removidas aqui.']);
        }

        $connection->delete();

        return response()->json(['success' => true, 'message' => 'Conexão removida.']);
    }

    public function connectionCategories(AnalyticsConnection $connection): JsonResponse
    {
        $categories = $this->wpService->fetchCategories($connection);
        return response()->json(['success' => true, 'categories' => $categories]);
    }

    // ===== CALENDÁRIO EDITORIAL =====

    /**
     * Página do calendário editorial
     */
    public function calendar(): Response
    {
        $brandId = session('current_brand_id');
        $categories = BlogCategory::forBrand($brandId)->orderBy('name')->get(['id', 'name']);
        $connections = $this->getWordPressConnections($brandId);

        return Inertia::render('Blog/Calendar', [
            'categories' => $categories,
            'connections' => $connections,
        ]);
    }

    /**
     * Listar itens do calendário (JSON)
     */
    public function calendarItems(Request $request): JsonResponse
    {
        $brandId = session('current_brand_id');
        $start = $request->input('start', now()->startOfMonth()->format('Y-m-d'));
        $end = $request->input('end', now()->endOfMonth()->format('Y-m-d'));

        $items = BlogCalendarItem::forBrand($brandId)
            ->forDateRange($start, $end)
            ->with(['article:id,title,status,slug', 'category:id,name'])
            ->orderBy('scheduled_date')
            ->get()
            ->map(fn($item) => [
                'id' => $item->id,
                'date' => $item->scheduled_date->format('Y-m-d'),
                'title' => $item->title,
                'description' => $item->description,
                'keywords' => $item->keywords,
                'tone' => $item->tone,
                'instructions' => $item->instructions,
                'estimated_word_count' => $item->estimated_word_count,
                'category' => $item->category?->name,
                'category_id' => $item->blog_category_id,
                'connection_id' => $item->wordpress_connection_id,
                'status' => $item->status,
                'status_label' => $item->status_label,
                'status_color' => $item->status_color,
                'article_id' => $item->article_id,
                'article_title' => $item->article?->title,
                'article_status' => $item->article?->status,
                'batch_id' => $item->batch_id,
                'batch_status' => $item->batch_status,
            ]);

        // Batches em draft (para aprovação)
        $draftBatches = BlogCalendarItem::forBrand($brandId)
            ->where('batch_status', 'draft')
            ->forDateRange($start, $end)
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
     * Gerar calendário de pautas com IA
     */
    public function generateCalendar(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'posts_per_week' => 'nullable|integer|min:1|max:7',
            'tone' => 'nullable|string|max:100',
            'instructions' => 'nullable|string|max:2000',
            'wordpress_connection_id' => 'nullable|integer',
            'blog_category_id' => 'nullable|integer',
            'ai_model' => 'nullable|string',
            'cover_width' => 'nullable|integer|min:100|max:4000',
            'cover_height' => 'nullable|integer|min:100|max:4000',
        ]);

        $brand = Auth::user()->getActiveBrand();
        if (!$brand) {
            return response()->json(['success' => false, 'error' => 'Nenhuma marca ativa.']);
        }

        $result = $this->calendarService->generateCalendar(
            brand: $brand,
            userId: Auth::id(),
            startDate: $request->input('start_date'),
            endDate: $request->input('end_date'),
            options: [
                'posts_per_week' => $request->input('posts_per_week', 2),
                'tone' => $request->input('tone'),
                'instructions' => $request->input('instructions'),
                'wordpress_connection_id' => $request->input('wordpress_connection_id'),
                'blog_category_id' => $request->input('blog_category_id'),
                'ai_model' => $request->input('ai_model', 'gpt-4o-mini'),
                'cover_width' => $request->input('cover_width', 1750),
                'cover_height' => $request->input('cover_height', 650),
                'batch_status' => 'draft',
            ],
        );

        return response()->json($result);
    }

    /**
     * Gerar artigo completo a partir de uma pauta
     */
    public function generateArticleFromItem(BlogCalendarItem $item): JsonResponse
    {
        $result = $this->calendarService->generateArticleFromItem($item);
        return response()->json($result);
    }

    /**
     * Gerar artigos para todas as pautas pendentes no período
     */
    public function generateAllArticles(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $brandId = session('current_brand_id');
        $result = $this->calendarService->generateArticlesForPendingItems(
            brandId: $brandId,
            startDate: $request->input('start_date'),
            endDate: $request->input('end_date'),
            limit: $request->input('limit', 10),
        );

        return response()->json(['success' => true, ...$result]);
    }

    /**
     * Atualizar pauta do calendário
     */
    public function updateCalendarItem(Request $request, BlogCalendarItem $item): JsonResponse
    {
        $validated = $request->validate([
            'scheduled_date' => 'nullable|date',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'keywords' => 'nullable|string|max:500',
            'tone' => 'nullable|string|max:100',
            'instructions' => 'nullable|string|max:2000',
            'estimated_word_count' => 'nullable|integer|min:200|max:5000',
            'wordpress_connection_id' => 'nullable|integer',
            'blog_category_id' => 'nullable|integer',
        ]);

        $item->update(array_filter($validated, fn($v) => $v !== null));

        return response()->json(['success' => true, 'message' => 'Pauta atualizada.']);
    }

    /**
     * Excluir pauta do calendário
     */
    public function destroyCalendarItem(BlogCalendarItem $item): JsonResponse
    {
        $item->delete();
        return response()->json(['success' => true, 'message' => 'Pauta removida.']);
    }

    /**
     * Aprovar batch de pautas
     */
    public function approveCalendarBatch(Request $request): JsonResponse
    {
        $request->validate(['batch_id' => 'required|string']);

        $updated = BlogCalendarItem::where('batch_id', $request->input('batch_id'))
            ->where('batch_status', 'draft')
            ->update(['batch_status' => 'approved']);

        return response()->json(['success' => true, 'updated' => $updated, 'message' => "{$updated} pauta(s) aprovada(s)."]);
    }

    /**
     * Rejeitar batch de pautas (remove todas)
     */
    public function rejectCalendarBatch(Request $request): JsonResponse
    {
        $request->validate(['batch_id' => 'required|string']);

        $deleted = BlogCalendarItem::where('batch_id', $request->input('batch_id'))
            ->where('batch_status', 'draft')
            ->delete();

        return response()->json(['success' => true, 'deleted' => $deleted, 'message' => "{$deleted} pauta(s) removida(s)."]);
    }

    /**
     * Aprovar pauta individual
     */
    public function approveCalendarItem(BlogCalendarItem $item): JsonResponse
    {
        if ($item->batch_status === 'draft') {
            $item->update(['batch_status' => 'approved']);
        }

        return response()->json(['success' => true, 'message' => 'Pauta aprovada.']);
    }

    /**
     * Limpar pautas pendentes de um período
     */
    public function clearCalendarPeriod(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        $brandId = session('current_brand_id');

        $deleted = BlogCalendarItem::forBrand($brandId)
            ->pending()
            ->forDateRange($request->input('start_date'), $request->input('end_date'))
            ->delete();

        return response()->json(['success' => true, 'deleted' => $deleted, 'message' => "{$deleted} pauta(s) removida(s)."]);
    }

    // ===== PRIVATE =====

    private function getWordPressConnections(?int $brandId): array
    {
        return AnalyticsConnection::where(function ($q) use ($brandId) {
                $q->where('platform', 'wordpress')
                    ->orWhere('platform', 'woocommerce');
            })
            ->when($brandId, fn($q) => $q->where('brand_id', $brandId))
            ->where('is_active', true)
            ->get(['id', 'name', 'platform', 'external_name', 'config'])
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'platform' => $c->platform,
                'platform_label' => $c->platform === 'wordpress' ? 'WordPress' : 'WooCommerce',
                'site_url' => $c->config['site_url'] ?? $c->config['store_url'] ?? '',
            ])
            ->toArray();
    }
}

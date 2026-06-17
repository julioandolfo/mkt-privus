<?php

namespace App\Http\Controllers;

use App\Enums\AIModel;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\SocialPlatform;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostSchedule;
use App\Models\SocialAccount;
use App\Services\AI\AIGateway;
use App\Services\Social\ContentGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        $query = Post::with(['media', 'user', 'brands:id,name'])
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

        $posts = $query->paginate(12)->through(function ($post) {
            $schedules = $post->schedules()->where('status', 'published')->get();
            $hasMetrics = $schedules->whereNotNull('metrics_synced_at')->isNotEmpty();

            $metrics = $hasMetrics ? [
                'likes' => $schedules->sum('likes'),
                'comments' => $schedules->sum('comments'),
                'shares' => $schedules->sum('shares'),
                'saves' => $schedules->sum('saves'),
                'reach' => $schedules->sum('reach'),
                'impressions' => $schedules->sum('impressions'),
                'video_views' => $schedules->sum('video_views'),
                'engagement_rate' => $schedules->avg('engagement_rate'),
                'total_engagement' => $schedules->sum('likes') + $schedules->sum('comments') + $schedules->sum('shares') + $schedules->sum('saves'),
                'synced_at' => $schedules->max('metrics_synced_at')?->diffForHumans(),
            ] : null;

            $brandsList = $post->brands->pluck('name')->values();

            // Status real por conta: pega o schedule mais recente de cada conta/plataforma
            // e expõe os que falharam. Permite à UI sinalizar falha por rede mesmo quando
            // o post está marcado como "Publicado" (publicação parcial).
            $failedPlatforms = $post->schedules()
                ->get()
                ->groupBy(fn($s) => $s->social_account_id ?: ('platform:' . ($s->platform->value ?? $s->platform)))
                ->map(fn($group) => $group->sortByDesc('id')->first())
                ->filter(fn($s) => $s->status === 'failed')
                ->map(fn($s) => [
                    'platform' => $s->platform instanceof SocialPlatform ? $s->platform->value : (string) $s->platform,
                    'platform_label' => $s->platform instanceof SocialPlatform ? $s->platform->label() : (string) $s->platform,
                    'error' => $s->error_message,
                ])
                ->values()
                ->all();

            return [
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
                'failed_platforms' => $failedPlatforms,
                'scheduled_at' => $post->scheduled_at?->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i'),
                'published_at' => $post->published_at?->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i'),
                'created_at' => $post->created_at->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i'),
                'user_name' => $post->user?->name,
                'metrics' => $metrics,
                'is_shared' => $brandsList->count() > 1,
                'brand_count' => $brandsList->count(),
                'brand_names' => $brandsList->all(),
                'media' => $post->media->map(fn($m) => [
                    'id' => $m->id,
                    'type' => $m->type,
                    'file_path' => $m->file_path ? Storage::url($m->file_path) : null,
                    'file_name' => $m->file_name,
                    'alt_text' => $m->alt_text,
                ]),
            ];
        });

        // Estatisticas
        $stats = [
            'drafts' => Post::forBrand($brand->id)->drafts()->count(),
            'scheduled' => Post::forBrand($brand->id)->scheduled()->count(),
            'published' => Post::forBrand($brand->id)->published()->count(),
            'failed' => Post::forBrand($brand->id)->where('status', PostStatus::Failed)->count(),
        ];

        // Métricas globais de engajamento
        $brandPostIds = Post::forBrand($brand->id)->pluck('id');
        $publishedSchedules = PostSchedule::whereIn('post_id', $brandPostIds)
            ->where('status', 'published')
            ->whereNotNull('metrics_synced_at')
            ->get();

        $engagementStats = null;
        if ($publishedSchedules->isNotEmpty()) {
            $totalLikes = $publishedSchedules->sum('likes');
            $totalComments = $publishedSchedules->sum('comments');
            $totalShares = $publishedSchedules->sum('shares');
            $totalSaves = $publishedSchedules->sum('saves');
            $totalReach = $publishedSchedules->sum('reach');
            $totalImpressions = $publishedSchedules->sum('impressions');
            $totalEngagement = $totalLikes + $totalComments + $totalShares + $totalSaves;

            $engagementStats = [
                'total_likes' => $totalLikes,
                'total_comments' => $totalComments,
                'total_shares' => $totalShares,
                'total_saves' => $totalSaves,
                'total_reach' => $totalReach,
                'total_impressions' => $totalImpressions,
                'total_engagement' => $totalEngagement,
                'avg_engagement_rate' => round($publishedSchedules->avg('engagement_rate'), 2),
                'posts_with_metrics' => $publishedSchedules->count(),
            ];
        }

        // Top 5 posts por engajamento
        $topPosts = [];
        if ($publishedSchedules->isNotEmpty()) {
            $topSchedules = $publishedSchedules->sortByDesc(function ($s) {
                return $s->likes + $s->comments + $s->shares + $s->saves;
            })->take(5);

            foreach ($topSchedules as $schedule) {
                $post = $schedule->post;
                if (!$post) continue;
                $post->load('media');
                $topPosts[] = [
                    'id' => $post->id,
                    'title' => $post->title,
                    'caption' => mb_substr($post->caption ?? '', 0, 100),
                    'type' => $post->type?->value,
                    'platforms' => $post->platforms ?? [],
                    'published_at' => $post->published_at?->setTimezone(config('app.display_timezone'))->format('d/m/Y'),
                    'platform' => $schedule->platform->value ?? $schedule->platform,
                    'likes' => $schedule->likes,
                    'comments' => $schedule->comments,
                    'shares' => $schedule->shares,
                    'saves' => $schedule->saves,
                    'reach' => $schedule->reach,
                    'engagement_rate' => $schedule->engagement_rate,
                    'total_engagement' => $schedule->likes + $schedule->comments + $schedule->shares + $schedule->saves,
                    'media_url' => $post->media->first()?->file_path ? Storage::url($post->media->first()->file_path) : null,
                    'media_type' => $post->media->first()?->type,
                    'platform_post_url' => $schedule->platform_post_url,
                ];
            }
        }

        return Inertia::render('Social/Posts/Index', [
            'posts' => $posts,
            'filters' => $request->only(['status', 'platform', 'type', 'search']),
            'stats' => $stats,
            'engagementStats' => $engagementStats,
            'topPosts' => $topPosts,
            'platforms' => $this->getPlatformOptions(),
            'statuses' => $this->getStatusOptions(),
        ]);
    }

    /**
     * Formulario de criacao de post
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Social/Posts/Create', [
            'platforms' => $this->getPlatformOptions(),
            'postTypes' => $this->getPostTypeOptions(),
            'accounts' => $this->buildAccessibleAccountsPayload($request),
            'activeBrandId' => $request->user()->getActiveBrand()?->id,
            'aiModels' => $this->getAIModelOptions(),
        ]);
    }

    /**
     * Retorna todas as contas sociais ativas das brands às quais o usuário
     * tem acesso (via pivô brand_user). Cada item inclui brand_id e brand_name
     * para a UI agrupar por empresa e o usuário escolher cross-brand.
     */
    private function buildAccessibleAccountsPayload(Request $request): array
    {
        $brandIds = $request->user()->brands()->pluck('brands.id');

        if ($brandIds->isEmpty()) {
            return [];
        }

        return SocialAccount::whereIn('brand_id', $brandIds)
            ->where('is_active', true)
            ->with('brand:id,name')
            ->orderBy('brand_id')
            ->orderBy('platform')
            ->get()
            ->map(fn($acc) => [
                'id' => $acc->id,
                'brand_id' => $acc->brand_id,
                'brand_name' => $acc->brand?->name,
                'platform' => $acc->platform->value,
                'platform_label' => $acc->platform->label(),
                'platform_color' => $acc->platform->color(),
                'username' => $acc->username,
                'display_name' => $acc->display_name,
                'avatar_url' => $acc->avatar_url,
                'source' => $acc->source ?? 'direct',
            ])
            ->toArray();
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

        // Normaliza o horário local informado (display_timezone) para UTC ANTES
        // de validar, para que "after:2 minutes ago" (avaliado em UTC) compare o
        // instante real — caso contrário um agendamento BRT próximo falharia.
        $this->normalizeScheduledAtToUtc($request);

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'caption' => 'required|string|max:10000',
            'hashtags' => 'nullable|array',
            'hashtags.*' => 'string|max:100',
            'type' => 'required|string',
            'account_ids' => 'nullable|array',
            'account_ids.*' => 'integer|exists:social_accounts,id',
            'platforms' => 'nullable|array',
            'platforms.*' => 'string',
            'scheduled_at' => 'nullable|date|after:2 minutes ago',
            'media' => 'nullable|array|max:10',
            'media.*' => 'file|extensions:jpg,jpeg,png,gif,webp,mp4|max:102400',
        ]);

        // Resolve as contas selecionadas. Aceita o novo fluxo (account_ids[]) e
        // faz fallback para o legado (platforms[] + brand ativa) se necessário.
        $accounts = $this->resolveSelectedAccounts(
            $request,
            $validated['account_ids'] ?? null,
            $validated['platforms'] ?? null,
            $brand->id,
        );

        if ($accounts === null) {
            return redirect()->back()->withErrors([
                'account_ids' => 'Você não tem acesso a uma das contas selecionadas.',
            ]);
        }

        if ($accounts->isEmpty() && empty($validated['platforms'] ?? [])) {
            return redirect()->back()->withErrors([
                'account_ids' => 'Selecione pelo menos uma conta para publicar.',
            ]);
        }

        $platforms = $accounts->isNotEmpty()
            ? $accounts->pluck('platform')->map(fn($p) => $p instanceof SocialPlatform ? $p->value : $p)->unique()->values()->all()
            : ($validated['platforms'] ?? []);

        $brandIds = $accounts->pluck('brand_id')->filter()->unique()->values();

        // Já normalizado para UTC em normalizeScheduledAtToUtc().
        $scheduledAt = !empty($validated['scheduled_at'])
            ? \Carbon\Carbon::parse($validated['scheduled_at'])
            : null;

        $status = $scheduledAt
            ? PostStatus::Scheduled
            : PostStatus::Draft;

        $post = Post::create([
            'brand_id' => $brandIds->first() ?? $brand->id,
            'user_id' => $request->user()->id,
            'title' => $validated['title'] ?? null,
            'caption' => $validated['caption'],
            'hashtags' => $validated['hashtags'] ?? [],
            'type' => $validated['type'],
            'status' => $status,
            'platforms' => $platforms,
            'scheduled_at' => $scheduledAt,
        ]);

        // Vincula o post a TODAS as brands envolvidas (cross-brand visibility).
        if ($brandIds->isNotEmpty()) {
            $post->brands()->sync($brandIds->all());
        } else {
            $post->brands()->sync([$brand->id]);
        }

        // Upload de midias
        if ($request->hasFile('media')) {
            $normalizer = app(\App\Services\Social\VideoNormalizerService::class);
            $isReel     = ($validated['type'] ?? '') === 'reel';

            foreach ($request->file('media') as $index => $file) {
                $path = $file->store("posts/{$post->id}", 'public');
                $mimeType = $file->getMimeType();
                // Classifica por extensão também: alguns .mp4 chegam com mime vazio/genérico
                // e cairiam erroneamente como imagem (quebrando a publicação no Instagram).
                $isVideo = str_starts_with((string) $mimeType, 'video/')
                    || strtolower($file->getClientOriginalExtension()) === 'mp4';

                if ($isVideo && !str_starts_with((string) $mimeType, 'video/')) {
                    $mimeType = 'video/mp4';
                }

                // Normalizar vídeo para Reels (1080×1920, H.264+AAC)
                if ($isVideo && $isReel && $normalizer->isAvailable()) {
                    $path = $this->normalizeVideoForReels($normalizer, $path);
                    $mimeType = 'video/mp4';
                }

                $fullPath = storage_path('app/public/' . $path);

                PostMedia::create([
                    'post_id' => $post->id,
                    'type' => $isVideo ? 'video' : 'image',
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $mimeType,
                    'file_size' => file_exists($fullPath) ? filesize($fullPath) : $file->getSize(),
                    'order' => $index,
                ]);
            }
        }

        // Criar PostSchedules diretamente das contas selecionadas (cross-brand).
        if (!empty($validated['scheduled_at']) && $accounts->isNotEmpty()) {
            $this->syncSchedulesFromAccounts($post, $accounts);
        } elseif (!empty($validated['scheduled_at']) && $accounts->isEmpty() && $brand) {
            // Fallback legado: platforms[] + brand ativa (chamadas antigas).
            $this->syncPostSchedules($post, $brand->id);
        }

        $scheduledCount = $post->schedules()->count();
        $brandCount = $brandIds->count() ?: 1;
        $msg = 'Post criado com sucesso!';
        if (!empty($validated['scheduled_at']) && $scheduledCount === 0) {
            $msg .= ' Atenção: nenhuma conta social conectada encontrada para publicação automática. Conecte contas em Social > Contas.';
        } elseif (!empty($validated['scheduled_at']) && $scheduledCount > 0) {
            $msg .= $brandCount > 1
                ? " Agendado para {$scheduledCount} conta(s) em {$brandCount} marca(s)."
                : " Agendado para {$scheduledCount} conta(s).";
        }

        return redirect()->route('social.posts.index')->with('success', $msg);
    }

    /**
     * Converte o campo scheduled_at do request — informado pelo usuário no fuso
     * local (config app.display_timezone, via <input type="datetime-local">,
     * portanto sem offset) — para um datetime UTC, regravando no request antes
     * da validação. Assim "after:..." compara o instante real e o downstream
     * apenas faz Carbon::parse() (UTC). No-op se o campo estiver vazio.
     */
    private function normalizeScheduledAtToUtc(Request $request): void
    {
        $value = $request->input('scheduled_at');

        if (empty($value)) {
            return;
        }

        $request->merge([
            'scheduled_at' => \Carbon\Carbon::parse($value, config('app.display_timezone'))
                ->utc()
                ->toDateTimeString(),
        ]);
    }

    /**
     * Carrega os SocialAccount IDs informados e valida que todas pertencem
     * a brands em que o usuário tem acesso (via pivô brand_user).
     *
     * Retorna:
     *  - Collection de SocialAccount (vazia se nada selecionado e fluxo legado)
     *  - null se houve violação de permissão (caller deve abortar)
     */
    private function resolveSelectedAccounts(
        Request $request,
        ?array $accountIds,
        ?array $platforms,
        int $activeBrandId,
    ): ?\Illuminate\Support\Collection {
        if (empty($accountIds)) {
            return collect();
        }

        $userBrandIds = $request->user()->brands()->pluck('brands.id')->all();
        $accounts = SocialAccount::whereIn('id', $accountIds)
            ->where('is_active', true)
            ->get();

        // Permissão: cada conta selecionada deve pertencer a uma brand do usuário.
        foreach ($accounts as $account) {
            if (!in_array($account->brand_id, $userBrandIds, true)) {
                return null;
            }
        }

        return $accounts;
    }

    /**
     * Cria/atualiza PostSchedules a partir da lista de contas selecionadas.
     * Cada conta vira um schedule, com platform vindo do próprio SocialAccount.
     */
    private function syncSchedulesFromAccounts(Post $post, \Illuminate\Support\Collection $accounts): void
    {
        $scheduledAt = $post->scheduled_at;

        if (!$scheduledAt) {
            return;
        }

        // Remove schedules de contas que não estão mais selecionadas (apenas pendentes).
        PostSchedule::where('post_id', $post->id)
            ->whereIn('status', ['pending', 'publishing'])
            ->whereNotIn('social_account_id', $accounts->pluck('id'))
            ->delete();

        foreach ($accounts as $account) {
            $existing = PostSchedule::where('post_id', $post->id)
                ->where('social_account_id', $account->id)
                ->whereIn('status', ['pending', 'publishing'])
                ->first();

            if ($existing) {
                $existing->update(['scheduled_at' => $scheduledAt]);
                continue;
            }

            PostSchedule::create([
                'post_id'           => $post->id,
                'social_account_id' => $account->id,
                'platform'          => $account->platform->value,
                'status'            => 'pending',
                'scheduled_at'      => $scheduledAt,
                'attempts'          => 0,
                'max_attempts'      => 3,
            ]);
        }

        Log::info("PostSchedule cross-brand: {$accounts->count()} schedule(s) sincronizados para post #{$post->id}", [
            'scheduled_at' => $scheduledAt,
            'brands' => $accounts->pluck('brand_id')->unique()->values()->all(),
        ]);
    }

    /**
     * Formulario de edicao de post
     */
    public function edit(Request $request, Post $post): Response
    {
        $this->authorizePost($request, $post);

        $post->load(['media', 'schedules']);

        // Pré-marcação: ids das contas que já têm schedules vivos.
        $selectedAccountIds = $post->schedules
            ->whereIn('status', ['pending', 'publishing', 'published'])
            ->pluck('social_account_id')
            ->unique()
            ->values()
            ->all();

        return Inertia::render('Social/Posts/Edit', [
            'post' => [
                'id' => $post->id,
                'title' => $post->title,
                'caption' => $post->caption,
                'hashtags' => $post->hashtags ?? [],
                'type' => $post->type?->value,
                'status' => $post->status->value,
                'platforms' => $post->platforms ?? [],
                'scheduled_at' => $post->scheduled_at?->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i'),
                'ai_model_used' => $post->ai_model_used,
                'ai_prompt' => $post->ai_prompt,
                'selected_account_ids' => $selectedAccountIds,
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
            'accounts' => $this->buildAccessibleAccountsPayload($request),
            'activeBrandId' => $request->user()->getActiveBrand()?->id,
            'aiModels' => $this->getAIModelOptions(),
        ]);
    }

    /**
     * Atualizar post existente
     */
    public function update(Request $request, Post $post): RedirectResponse
    {
        $this->authorizePost($request, $post);

        // Ver store(): horário local -> UTC antes da validação.
        $this->normalizeScheduledAtToUtc($request);

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'caption' => 'required|string|max:10000',
            'hashtags' => 'nullable|array',
            'hashtags.*' => 'string|max:100',
            'type' => 'required|string',
            'account_ids' => 'nullable|array',
            'account_ids.*' => 'integer|exists:social_accounts,id',
            'platforms' => 'nullable|array',
            'platforms.*' => 'string',
            'scheduled_at' => 'nullable|date',
            'status' => 'nullable|string',
            'media' => 'nullable|array|max:10',
            'media.*' => 'file|extensions:jpg,jpeg,png,gif,webp,mp4|max:102400',
            'remove_media' => 'nullable|array',
            'remove_media.*' => 'integer',
        ]);

        $brand = $request->user()->getActiveBrand();

        $accounts = $this->resolveSelectedAccounts(
            $request,
            $validated['account_ids'] ?? null,
            $validated['platforms'] ?? null,
            $brand?->id ?? 0,
        );

        if ($accounts === null) {
            return redirect()->back()->withErrors([
                'account_ids' => 'Você não tem acesso a uma das contas selecionadas.',
            ]);
        }

        $platforms = $accounts->isNotEmpty()
            ? $accounts->pluck('platform')->map(fn($p) => $p instanceof SocialPlatform ? $p->value : $p)->unique()->values()->all()
            : ($validated['platforms'] ?? $post->platforms ?? []);

        // Já normalizado para UTC em normalizeScheduledAtToUtc().
        $scheduledAt = !empty($validated['scheduled_at'])
            ? \Carbon\Carbon::parse($validated['scheduled_at'])
            : null;

        // Determinar status
        $status = $validated['status'] ?? $post->status->value;
        if (!$validated['status'] && $scheduledAt && $post->status === PostStatus::Draft) {
            $status = PostStatus::Scheduled->value;
        }

        $post->update([
            'title' => $validated['title'] ?? null,
            'caption' => $validated['caption'],
            'hashtags' => $validated['hashtags'] ?? [],
            'type' => $validated['type'],
            'platforms' => $platforms,
            'scheduled_at' => $scheduledAt,
            'status' => $status,
        ]);

        // Re-sincroniza pivô brands se contas explícitas foram fornecidas.
        if ($accounts->isNotEmpty()) {
            $brandIds = $accounts->pluck('brand_id')->filter()->unique()->values()->all();
            if (!empty($brandIds)) {
                $post->brands()->sync($brandIds);
            }
        }

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
            $normalizer = app(\App\Services\Social\VideoNormalizerService::class);
            $isReel     = ($validated['type'] ?? $post->type?->value) === 'reel';
            $maxOrder   = $post->media()->max('order') ?? -1;

            foreach ($request->file('media') as $index => $file) {
                $path = $file->store("posts/{$post->id}", 'public');
                $mimeType = $file->getMimeType();
                // Classifica por extensão também: alguns .mp4 chegam com mime vazio/genérico
                // e cairiam erroneamente como imagem (quebrando a publicação no Instagram).
                $isVideo = str_starts_with((string) $mimeType, 'video/')
                    || strtolower($file->getClientOriginalExtension()) === 'mp4';

                if ($isVideo && !str_starts_with((string) $mimeType, 'video/')) {
                    $mimeType = 'video/mp4';
                }

                if ($isVideo && $isReel && $normalizer->isAvailable()) {
                    $path = $this->normalizeVideoForReels($normalizer, $path);
                    $mimeType = 'video/mp4';
                }

                $fullPath = storage_path('app/public/' . $path);

                PostMedia::create([
                    'post_id' => $post->id,
                    'type' => $isVideo ? 'video' : 'image',
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $mimeType,
                    'file_size' => file_exists($fullPath) ? filesize($fullPath) : $file->getSize(),
                    'order' => $maxOrder + $index + 1,
                ]);
            }
        }

        // Sincronizar PostSchedules se há agendamento
        if (!empty($validated['scheduled_at'])) {
            if ($accounts->isNotEmpty()) {
                $this->syncSchedulesFromAccounts($post, $accounts);
            } elseif ($brand) {
                // Fallback legado: platforms[] sem account_ids[].
                $this->syncPostSchedules($post, $brand->id);
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
     * Publicar post imediatamente (manual), sem aguardar o scheduler.
     * Cria PostSchedules se necessário e dispara PublishPostJob agora.
     * Responde JSON para chamadas AJAX do frontend.
     */
    public function publishNow(Request $request, Post $post): \Illuminate\Http\JsonResponse
    {
        $this->authorizePost($request, $post);

        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json(['message' => 'Nenhuma marca ativa.'], 422);
        }

        if (in_array($post->status->value, ['published', 'publishing'])) {
            return response()->json(['message' => 'Este post já foi publicado ou está sendo publicado.'], 422);
        }

        $now = now();
        $post->update([
            'scheduled_at' => $now,
            'status'       => 'publishing',
        ]);

        $accounts = SocialAccount::where('brand_id', $brand->id)
            ->where('is_active', true)
            ->whereIn('platform', $post->platforms ?? [])
            ->get();

        if ($accounts->isEmpty()) {
            $post->update(['status' => 'draft', 'scheduled_at' => null]);
            return response()->json([
                'message' => 'Nenhuma conta social conectada encontrada para as plataformas selecionadas. Conecte contas em Social > Contas.',
            ], 422);
        }

        $results = [];

        foreach ($accounts as $account) {
            $schedule = PostSchedule::where('post_id', $post->id)
                ->where('social_account_id', $account->id)
                ->latest()
                ->first();

            if ($schedule && $schedule->status === 'published') {
                continue;
            }

            if ($schedule) {
                $schedule->update([
                    'status'            => 'publishing',
                    'scheduled_at'      => $now,
                    'attempts'          => $schedule->attempts + 1,
                    'last_attempted_at' => $now,
                    'error_message'     => null,
                ]);
            } else {
                $schedule = PostSchedule::create([
                    'post_id'           => $post->id,
                    'social_account_id' => $account->id,
                    'platform'          => $account->platform->value,
                    'status'            => 'publishing',
                    'scheduled_at'      => $now,
                    'attempts'          => 1,
                    'max_attempts'      => 3,
                    'last_attempted_at' => $now,
                ]);
            }

            // Executar publicação síncrona (não depende do queue worker)
            try {
                \App\Jobs\PublishPostJob::dispatchSync($schedule);
                // dispatchSync não lança ao falhar o publish — o job marca o schedule
                // como 'failed' e retorna. Confere o status real em vez de assumir sucesso.
                $schedule->refresh();
                $ok = $schedule->status === 'published';
                $results[] = [
                    'account'  => $account->username,
                    'platform' => $account->platform->value,
                    'ok'       => $ok,
                    'error'    => $ok ? null : ($schedule->error_message ?? 'Falha desconhecida'),
                ];
            } catch (\Throwable $e) {
                $schedule->markAsFailed("Erro na publicação: {$e->getMessage()}");
                $results[] = ['account' => $account->username, 'platform' => $account->platform->value, 'ok' => false, 'error' => $e->getMessage()];
                \App\Models\SystemLog::error('social', 'post.publish_now.error', "Erro ao publicar post #{$post->id} em {$account->platform->value}", [
                    'post_id'    => $post->id,
                    'account_id' => $account->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        if (empty($results)) {
            $post->update(['status' => 'published', 'published_at' => now()]);
            return response()->json(['message' => 'Post já publicado em todas as contas conectadas.']);
        }

        $succeeded = collect($results)->where('ok', true)->count();
        $failed = collect($results)->where('ok', false);

        \App\Models\SystemLog::info('social', 'post.publish_now', "Publicação manual do post #{$post->id}: {$succeeded}/" . count($results) . " conta(s) OK", [
            'post_id'    => $post->id,
            'brand_id'   => $brand->id,
            'platforms'  => $post->platforms,
            'results'    => $results,
            'user_id'    => $request->user()->id,
        ]);

        // Atualizar status do post baseado nos resultados
        $post->refresh();
        $allSchedules = $post->schedules()->get();
        $allPublished = $allSchedules->every(fn($s) => $s->status === 'published');
        $anyPublished = $allSchedules->contains(fn($s) => $s->status === 'published');
        $allFinal = $allSchedules->every(fn($s) => in_array($s->status, ['published', 'failed']));

        if ($allPublished) {
            $post->update(['status' => PostStatus::Published, 'published_at' => now()]);
        } elseif ($anyPublished && $allFinal) {
            $post->update(['status' => PostStatus::Published, 'published_at' => now()]);
        } elseif ($allFinal) {
            $post->update(['status' => PostStatus::Failed]);
        }

        if ($failed->isNotEmpty()) {
            $errorSummary = $failed->map(fn($r) => "{$r['platform']}: {$r['error']}")->implode('; ');
            return response()->json([
                'message' => $succeeded > 0
                    ? "Publicado em {$succeeded} conta(s), mas falhou em {$failed->count()}: {$errorSummary}"
                    : "Falha na publicação: {$errorSummary}",
                'results' => $results,
            ], $succeeded > 0 ? 200 : 422);
        }

        return response()->json([
            'message'  => "Publicado com sucesso em {$succeeded} conta(s)!",
            'results'  => $results,
        ]);
    }

    /**
     * Cancela uma publicação travada (status publishing) — volta o post para draft.
     */
    public function cancelPublish(Request $request, Post $post): \Illuminate\Http\JsonResponse
    {
        $this->authorizePost($request, $post);

        if ($post->status !== PostStatus::Publishing) {
            return response()->json(['message' => 'Este post não está em estado de publicação.'], 422);
        }

        $post->schedules()
            ->whereIn('status', ['pending', 'publishing'])
            ->update([
                'status' => 'failed',
                'error_message' => 'Publicação cancelada pelo usuário',
            ]);

        $post->update([
            'status' => PostStatus::Draft,
            'scheduled_at' => null,
        ]);

        \App\Models\SystemLog::info('social', 'post.publish_cancelled', "Publicação do post #{$post->id} cancelada pelo usuário", [
            'post_id' => $post->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Publicação cancelada. O post voltou para rascunho.']);
    }

    /**
     * Republica um post já publicado (ou falhado) — cria novos PostSchedules e dispara imediatamente.
     */
    public function republish(Request $request, Post $post): \Illuminate\Http\JsonResponse
    {
        $this->authorizePost($request, $post);

        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json(['message' => 'Nenhuma marca ativa.'], 422);
        }

        if ($post->status->value === 'publishing') {
            return response()->json(['message' => 'Este post já está sendo publicado.'], 422);
        }

        $accounts = SocialAccount::where('brand_id', $brand->id)
            ->where('is_active', true)
            ->whereIn('platform', $post->platforms ?? [])
            ->get();

        if ($accounts->isEmpty()) {
            return response()->json([
                'message' => 'Nenhuma conta social conectada para as plataformas selecionadas. Conecte contas em Social > Contas.',
            ], 422);
        }

        $now = now();

        // Resetar o post para publishing
        $post->update([
            'status'       => 'publishing',
            'scheduled_at' => $now,
            'published_at' => null,
        ]);

        $results = [];

        foreach ($accounts as $account) {
            $schedule = PostSchedule::create([
                'post_id'           => $post->id,
                'social_account_id' => $account->id,
                'platform'          => $account->platform->value,
                'status'            => 'publishing',
                'scheduled_at'      => $now,
                'attempts'          => 1,
                'max_attempts'      => 3,
                'last_attempted_at' => $now,
            ]);

            try {
                \App\Jobs\PublishPostJob::dispatchSync($schedule);
                // dispatchSync não lança ao falhar o publish — o job marca o schedule
                // como 'failed' e retorna. Confere o status real em vez de assumir sucesso.
                $schedule->refresh();
                $ok = $schedule->status === 'published';
                $results[] = [
                    'account'  => $account->username,
                    'platform' => $account->platform->value,
                    'ok'       => $ok,
                    'error'    => $ok ? null : ($schedule->error_message ?? 'Falha desconhecida'),
                ];
            } catch (\Throwable $e) {
                $schedule->markAsFailed("Erro na republicação: {$e->getMessage()}");
                $results[] = ['account' => $account->username, 'platform' => $account->platform->value, 'ok' => false, 'error' => $e->getMessage()];
                \App\Models\SystemLog::error('social', 'post.republish.error', "Erro ao republicar post #{$post->id} em {$account->platform->value}", [
                    'post_id'    => $post->id,
                    'account_id' => $account->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $succeeded = collect($results)->where('ok', true)->count();
        $failed = collect($results)->where('ok', false);

        \App\Models\SystemLog::info('social', 'post.republish', "Republicação do post #{$post->id}: {$succeeded}/" . count($results) . " conta(s) OK", [
            'post_id'    => $post->id,
            'brand_id'   => $brand->id,
            'platforms'  => $post->platforms,
            'results'    => $results,
            'user_id'    => $request->user()->id,
        ]);

        // Atualizar status do post
        $post->refresh();
        $allSchedules = $post->schedules()->get();
        $anyPublished = $allSchedules->contains(fn($s) => $s->status === 'published');
        $allFinal = $allSchedules->every(fn($s) => in_array($s->status, ['published', 'failed']));

        if ($anyPublished && $allFinal) {
            $post->update(['status' => PostStatus::Published, 'published_at' => now()]);
        } elseif ($allFinal && !$anyPublished) {
            $post->update(['status' => PostStatus::Failed]);
        }

        if ($failed->isNotEmpty()) {
            $errorSummary = $failed->map(fn($r) => "{$r['platform']}: {$r['error']}")->implode('; ');
            return response()->json([
                'message' => $succeeded > 0
                    ? "Republicado em {$succeeded} conta(s), mas falhou em {$failed->count()}: {$errorSummary}"
                    : "Falha na republicação: {$errorSummary}",
            ], $succeeded > 0 ? 200 : 422);
        }

        return response()->json([
            'message' => "Republicado com sucesso em {$succeeded} conta(s)!",
        ]);
    }

    /**
     * Reenvia apenas as contas cujo schedule mais recente falhou — sem tocar nas
     * que já publicaram (evita duplicar o post nas redes que deram certo).
     */
    public function republishFailed(Request $request, Post $post): \Illuminate\Http\JsonResponse
    {
        $this->authorizePost($request, $post);

        if ($post->status->value === 'publishing') {
            return response()->json(['message' => 'Este post já está sendo publicado.'], 422);
        }

        // Contas cujo schedule mais recente está 'failed'.
        $failedAccountIds = $post->schedules()
            ->get()
            ->groupBy(fn($s) => $s->social_account_id ?: ('platform:' . ($s->platform->value ?? $s->platform)))
            ->map(fn($group) => $group->sortByDesc('id')->first())
            ->filter(fn($s) => $s->status === 'failed' && $s->social_account_id)
            ->pluck('social_account_id')
            ->unique()
            ->values();

        if ($failedAccountIds->isEmpty()) {
            return response()->json(['message' => 'Nenhuma publicação falhada para reenviar.'], 422);
        }

        $accounts = SocialAccount::whereIn('id', $failedAccountIds)
            ->where('is_active', true)
            ->get();

        if ($accounts->isEmpty()) {
            return response()->json([
                'message' => 'As contas que falharam não estão mais conectadas/ativas. Reconecte em Social > Contas.',
            ], 422);
        }

        $now = now();
        $post->update(['status' => 'publishing', 'scheduled_at' => $now]);

        $results = [];

        foreach ($accounts as $account) {
            $schedule = PostSchedule::where('post_id', $post->id)
                ->where('social_account_id', $account->id)
                ->latest('id')
                ->first();

            if ($schedule && $schedule->status === 'published') {
                continue;
            }

            if ($schedule) {
                $schedule->update([
                    'status'            => 'publishing',
                    'scheduled_at'      => $now,
                    'attempts'          => $schedule->attempts + 1,
                    'last_attempted_at' => $now,
                    'error_message'     => null,
                ]);
            } else {
                $schedule = PostSchedule::create([
                    'post_id'           => $post->id,
                    'social_account_id' => $account->id,
                    'platform'          => $account->platform->value,
                    'status'            => 'publishing',
                    'scheduled_at'      => $now,
                    'attempts'          => 1,
                    'max_attempts'      => 3,
                    'last_attempted_at' => $now,
                ]);
            }

            try {
                \App\Jobs\PublishPostJob::dispatchSync($schedule);
                // dispatchSync não lança ao falhar o publish — o job marca o schedule
                // como 'failed' e retorna. Confere o status real em vez de assumir sucesso.
                $schedule->refresh();
                $ok = $schedule->status === 'published';
                $results[] = [
                    'account'  => $account->username,
                    'platform' => $account->platform->value,
                    'ok'       => $ok,
                    'error'    => $ok ? null : ($schedule->error_message ?? 'Falha desconhecida'),
                ];
            } catch (\Throwable $e) {
                $schedule->markAsFailed("Erro na republicação: {$e->getMessage()}");
                $results[] = ['account' => $account->username, 'platform' => $account->platform->value, 'ok' => false, 'error' => $e->getMessage()];
                \App\Models\SystemLog::error('social', 'post.republish_failed.error', "Erro ao reenviar post #{$post->id} em {$account->platform->value}", [
                    'post_id'    => $post->id,
                    'account_id' => $account->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $succeeded = collect($results)->where('ok', true)->count();
        $failed = collect($results)->where('ok', false);

        \App\Models\SystemLog::info('social', 'post.republish_failed', "Reenvio das falhas do post #{$post->id}: {$succeeded}/" . count($results) . " conta(s) OK", [
            'post_id'   => $post->id,
            'results'   => $results,
            'user_id'   => $request->user()->id,
        ]);

        // Recalcula status — mantém "Publicado" em sucesso parcial (preserva published_at original).
        $post->refresh();
        $allSchedules = $post->schedules()->get();
        $anyPublished = $allSchedules->contains(fn($s) => $s->status === 'published');
        $allFinal = $allSchedules->every(fn($s) => in_array($s->status, ['published', 'failed']));

        if ($anyPublished && $allFinal) {
            $post->update(['status' => PostStatus::Published, 'published_at' => $post->published_at ?? now()]);
        } elseif ($allFinal && !$anyPublished) {
            $post->update(['status' => PostStatus::Failed]);
        }

        if ($failed->isNotEmpty()) {
            $errorSummary = $failed->map(fn($r) => "{$r['platform']}: {$r['error']}")->implode('; ');
            return response()->json([
                'message' => $succeeded > 0
                    ? "Reenviado em {$succeeded} conta(s), mas ainda falhou em {$failed->count()}: {$errorSummary}"
                    : "Falha ao reenviar: {$errorSummary}",
            ], $succeeded > 0 ? 200 : 422);
        }

        return response()->json([
            'message' => "Reenviado com sucesso em {$succeeded} conta(s)!",
        ]);
    }

    /**
     * Cria ou atualiza os PostSchedules para cada conta conectada correspondente às plataformas do post.
     * Somente cria schedules pendentes (não toca os que já foram published/publishing).
     */
    private function syncPostSchedules(Post $post, int $brandId): void
    {
        $scheduledAt = $post->scheduled_at;
        $platforms   = $post->platforms ?? [];

        if (!$scheduledAt || empty($platforms)) {
            return;
        }

        // Buscar contas ativas da marca para as plataformas selecionadas
        $accounts = SocialAccount::where('brand_id', $brandId)
            ->where('is_active', true)
            ->whereIn('platform', $platforms)
            ->get();

        if ($accounts->isEmpty()) {
            Log::warning("PostSchedule: nenhuma conta social encontrada para post #{$post->id}", [
                'platforms' => $platforms,
                'brand_id'  => $brandId,
            ]);
            return;
        }

        foreach ($accounts as $account) {
            // Não duplicar — apenas criar se não existir schedule pendente/ativo para essa conta
            $existing = PostSchedule::where('post_id', $post->id)
                ->where('social_account_id', $account->id)
                ->whereIn('status', ['pending', 'publishing'])
                ->first();

            if ($existing) {
                // Atualizar horário se mudou
                $existing->update(['scheduled_at' => $scheduledAt]);
                continue;
            }

            PostSchedule::create([
                'post_id'           => $post->id,
                'social_account_id' => $account->id,
                'platform'          => $account->platform->value,
                'status'            => 'pending',
                'scheduled_at'      => $scheduledAt,
                'attempts'          => 0,
                'max_attempts'      => 3,
            ]);
        }

        Log::info("PostSchedule: {$accounts->count()} schedule(s) criado(s)/atualizados para post #{$post->id}", [
            'scheduled_at' => $scheduledAt,
            'platforms'    => $platforms,
        ]);
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
     * Gerar post COMPLETO com IA (legenda + hashtags + título + imagem)
     * Analisa contexto da marca, histórico, site, redes sociais, etc.
     */
    public function generateCompletePost(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'topic' => 'required|string|max:500',
                'platform' => 'required|string',
                'type' => 'nullable|string',
                'tone' => 'nullable|string|max:100',
                'instructions' => 'nullable|string|max:2000',
                'model' => 'nullable|string',
                'analyze_history' => 'nullable',
                'analyze_website' => 'nullable',
                'analyze_social' => 'nullable',
                'generate_image' => 'nullable',
                'image_style' => 'nullable|string|max:200',
                'image_size' => 'nullable|string|in:1024x1024,1792x1024,1024x1792,1536x1024,1024x1536',
                'reference_images' => 'nullable|array|max:3',
                'reference_images.*' => 'image|max:10240',
                'context_urls' => 'nullable|array|max:5',
                'context_urls.*' => 'nullable|url|max:500',
            ]);

            $brand = $request->user()->getActiveBrand();
            if (!$brand) {
                return response()->json(['error' => 'Nenhuma marca ativa selecionada.'], 422);
            }

            $analyzeHistory = filter_var($validated['analyze_history'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $analyzeWebsite = filter_var($validated['analyze_website'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $analyzeSocial = filter_var($validated['analyze_social'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $shouldGenerateImage = filter_var($validated['generate_image'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $platform = SocialPlatform::from($validated['platform']);
            $postType = isset($validated['type']) ? PostType::from($validated['type']) : PostType::Feed;
            $aiModel = isset($validated['model']) ? AIModel::from($validated['model']) : AIModel::GPT4oMini;

            $extraContext = $this->buildEnrichedContext(
                $brand,
                $platform,
                analyzeHistory: $analyzeHistory,
                analyzeWebsite: $analyzeWebsite,
                analyzeSocial: $analyzeSocial,
            );

            // Buscar conteúdo de URLs customizadas enviadas pelo usuário
            $contextUrls = array_filter($validated['context_urls'] ?? []);
            $urlContext = '';
            if (!empty($contextUrls)) {
                $urlParts = [];
                foreach ($contextUrls as $ctxUrl) {
                    $fetched = $this->fetchUrlContent($ctxUrl);
                    if ($fetched) {
                        $urlParts[] = "CONTEÚDO DE {$ctxUrl}:\n{$fetched}";
                    }
                }
                if (!empty($urlParts)) {
                    $urlContext = "\n\nCONTEXTO EXTRAÍDO DOS LINKS FORNECIDOS (use estas informações para criar o post):\n" . implode("\n\n", $urlParts);
                }
            }

            $enrichedInstructions = trim(
                ($validated['instructions'] ?? '') . "\n\n" . $extraContext . $urlContext
            );

            $textResult = $this->contentGenerator->generateCaption(
                brand: $brand,
                user: $request->user(),
                platform: $platform,
                postType: $postType,
                topic: $validated['topic'],
                tone: $validated['tone'] ?? null,
                instructions: $enrichedInstructions,
                model: $aiModel,
            );

            $aiGateway = app(AIGateway::class);
            $titleResult = $aiGateway->chat(
                model: $aiModel,
                messages: [
                    ['role' => 'system', 'content' => 'Você gera títulos curtos (3-8 palavras) para posts de redes sociais. Responda APENAS com o título, sem aspas, sem explicação.'],
                    ['role' => 'user', 'content' => "Crie um título para um post sobre: {$validated['topic']}\n\nLegenda gerada: " . mb_substr($textResult['caption'] ?? '', 0, 200)],
                ],
                brand: $brand,
                user: $request->user(),
                feature: 'complete_post_title',
            );
            $title = trim($titleResult['content'] ?? '');

            $imageData = null;
            if ($shouldGenerateImage) {
                try {
                    $userRefImages = $this->extractReferenceImages($request);

                    // Onda 2: quando o usuário envia foto do produto, ela já vai como
                    // input_image direto para o gpt-image-2 (modo edit). Antes a gente
                    // também chamava o Vision para gerar uma DESCRIÇÃO textual da foto
                    // e injetava no prompt — passar duas vezes (imagem + descrição) era
                    // redundante, caro e ainda induzia o modelo a "reimaginar" detalhes
                    // que ele deveria preservar. Agora: se há imagem do produto, a
                    // imagem fala por si. Mantemos o Vision só como fallback (sem refs).
                    $referenceContext = empty($userRefImages)
                        ? $this->analyzeReferenceImages($request, $aiGateway, $brand)
                        : null;

                    // Referências de identidade visual da marca (sem logo — política no-logo para posts sociais)
                    $brandRefImages = $this->extractBrandLogoAndReferences($brand);

                    // Mesclar: refs da marca primeiro, depois imagens do usuário (max 4 total para a API)
                    $allRefImages = array_merge($brandRefImages, $userRefImages);
                    $allRefImages = array_slice($allRefImages, 0, 4);

                    Log::info("generateCompletePost image generation", [
                        'has_reference_files' => $request->hasFile('reference_images'),
                        'reference_files_count' => count($request->file('reference_images') ?? []),
                        'user_ref_images' => count($userRefImages),
                        'brand_ref_images' => count($brandRefImages),
                        'total_ref_images' => count($allRefImages),
                        'reference_context_length' => mb_strlen($referenceContext ?? ''),
                        'aesthetic' => $validated['image_style'] ?? 'auto',
                        'generate_image' => $shouldGenerateImage,
                    ]);

                    $imagePrompt = $this->buildImagePrompt(
                        $brand,
                        $validated['topic'],
                        $textResult['caption'] ?? '',
                        $platform,
                        $postType,
                        $validated['image_style'] ?? null,
                    );

                    if ($referenceContext) {
                        $imagePrompt .= "\n\nREFERENCE IMAGE DESCRIPTION: " . $referenceContext;
                    }

                    $userRefCount = count($userRefImages);
                    if ($userRefCount === 1) {
                        $imagePrompt .= "\n\nEDIT MODE: You are editing the provided product image. Keep ALL product details unchanged — logos, labels, text, barcodes, colors, shapes MUST remain pixel-perfect. Only invent the SURROUNDING scene (background, props, lighting context) per the photo style described above. Do NOT regenerate, reimagine, or artistically reinterpret the product itself.";
                    } elseif ($userRefCount > 1) {
                        $imagePrompt .= "\n\nIMPORTANT: Multiple product reference images were provided, each showing a DIFFERENT product. "
                            . "Generate an image for the FIRST product only (image 1). Each product should get its own separate post image. "
                            . "Focus EXCLUSIVELY on the product from image 1 — do NOT combine all products into a single image. "
                            . "Keep the product details unchanged — logos, labels, text, barcodes, colors, shapes MUST remain pixel-perfect. Only invent the surrounding scene.";
                    }

                    $imageData = $aiGateway->generateImage(
                        prompt: $imagePrompt,
                        brand: $brand,
                        user: $request->user(),
                        size: $validated['image_size'] ?? '1024x1024',
                        quality: 'auto',
                        referenceImages: !empty($allRefImages) ? $allRefImages : null,
                    );
                } catch (\Throwable $e) {
                    Log::warning("Image generation failed: {$e->getMessage()}");
                    $imageData = [
                        'error' => $e->getMessage(),
                        'image_prompt' => $imagePrompt ?? '',
                    ];
                }
            }

            return response()->json([
                'title' => $title,
                'caption' => $textResult['caption'] ?? '',
                'hashtags' => $textResult['hashtags'] ?? [],
                'image' => $imageData,
                'model' => $textResult['model'] ?? $aiModel->value,
                'context_used' => [
                    'history' => $analyzeHistory,
                    'website' => $analyzeWebsite,
                    'social' => $analyzeSocial,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $msgs = collect($e->errors())->flatten()->implode(' ');
            return response()->json(['error' => $msgs], 422);
        } catch (\Throwable $e) {
            Log::error("generateCompletePost error: {$e->getMessage()}", [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 2000),
            ]);
            return response()->json([
                'error' => 'Erro ao gerar post completo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Constrói contexto enriquecido a partir de análise da marca
     */
    private function buildEnrichedContext(
        \App\Models\Brand $brand,
        SocialPlatform $platform,
        bool $analyzeHistory = true,
        bool $analyzeWebsite = true,
        bool $analyzeSocial = true,
    ): string {
        $context = [];

        if ($analyzeHistory) {
            $recentPosts = Post::forBrand($brand->id)
                ->where('status', PostStatus::Published)
                ->latest('published_at')
                ->limit(10)
                ->get(['id', 'caption', 'platforms', 'published_at']);

            if ($recentPosts->isNotEmpty()) {
                $topPosts = $recentPosts->map(function ($p) {
                    $schedule = $p->schedules()
                        ->where('status', 'published')
                        ->whereNotNull('metrics_synced_at')
                        ->orderByDesc('likes')
                        ->first();

                    $likes = $schedule?->likes ?? 0;

                    return [
                        'caption_preview' => mb_substr($p->caption, 0, 100),
                        'platforms' => $p->platforms,
                        'likes' => $likes,
                        'comments' => $schedule?->comments ?? 0,
                        'engagement_rate' => $schedule?->engagement_rate ?? 0,
                    ];
                })->sortByDesc('likes')->take(5);

                $context[] = "ANÁLISE DE HISTÓRICO DE POSTS (top 5 por engajamento):";
                foreach ($topPosts as $i => $tp) {
                    $engLabel = $tp['engagement_rate'] > 0 ? " | {$tp['engagement_rate']}% eng" : '';
                    $context[] = "  " . ($i + 1) . ". [{$tp['likes']} likes, {$tp['comments']} comments{$engLabel}] \"{$tp['caption_preview']}...\"";
                }
                $context[] = "Use esse padrão como referência para tom, estilo e formato que mais engaja.";
            }
        }

        // Analisar website — buscar conteúdo real dos links
        if ($analyzeWebsite) {
            $urls = $brand->getAllUrls();
            if (!empty($urls)) {
                $context[] = "\nSITES E LINKS DA MARCA (use para CTAs e referências):";
                foreach ($urls as $url) {
                    $label = $url['label'] ?? $url['type'] ?? 'Link';
                    $context[] = "  - {$label}: {$url['url']}";
                }

                // Buscar conteúdo real dos links para contexto (max 3 URLs)
                $urlsToFetch = array_slice($urls, 0, 3);
                foreach ($urlsToFetch as $urlEntry) {
                    $fetchedContent = $this->fetchUrlContent($urlEntry['url'] ?? '');
                    if ($fetchedContent) {
                        $label = $urlEntry['label'] ?? $urlEntry['type'] ?? 'Site';
                        $context[] = "\n  CONTEÚDO EXTRAÍDO DE [{$label}] ({$urlEntry['url']}):";
                        $context[] = "  " . $fetchedContent;
                    }
                }
            }
        }

        // Analisar redes sociais conectadas
        if ($analyzeSocial) {
            $accounts = $brand->socialAccounts()->where('is_active', true)->get();
            if ($accounts->isNotEmpty()) {
                $context[] = "\nREDES SOCIAIS CONECTADAS:";
                foreach ($accounts as $acc) {
                    $platformName = $acc->platform instanceof \App\Enums\SocialPlatform ? $acc->platform->value : (string) $acc->platform;
                    $context[] = "  - {$platformName}: @{$acc->username} ({$acc->display_name})";
                }
                $context[] = "Considere fazer cross-referência entre as redes quando relevante.";
            }
        }

        // Público-alvo e tom
        if ($brand->target_audience) {
            $context[] = "\nPÚBLICO-ALVO: {$brand->target_audience}";
        }
        if ($brand->segment) {
            $context[] = "SEGMENTO: {$brand->segment}";
        }

        // Keywords da marca
        $keywords = is_array($brand->keywords) ? $brand->keywords : [];
        if (!empty($keywords)) {
            $context[] = "\nPALAVRAS-CHAVE DA MARCA: " . implode(', ', $keywords);
            $context[] = "Incorpore naturalmente algumas dessas palavras-chave no conteúdo.";
        }

        return implode("\n", $context);
    }

    /**
     * Busca e extrai conteúdo textual relevante de uma URL para enriquecer o contexto da IA.
     * Retorna texto resumido (max 800 chars) ou null em caso de falha.
     */
    private function fetchUrlContent(string $url): ?string
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; PrivusBot/1.0)',
                    'Accept' => 'text/html',
                ])
                ->get($url);

            if (!$response->successful()) {
                return null;
            }

            $html = $response->body();

            // Extrair título
            $title = '';
            if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) {
                $title = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            }

            // Extrair meta description
            $description = '';
            if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/si', $html, $m)) {
                $description = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            }

            // Extrair og:description como fallback
            if (!$description && preg_match('/<meta[^>]*property=["\']og:description["\'][^>]*content=["\'](.*?)["\']/si', $html, $m)) {
                $description = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            }

            // Extrair texto do body (limpo)
            $bodyText = '';
            if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $html, $m)) {
                $body = $m[1];
                // Remover script, style, nav, header, footer
                $body = preg_replace('/<(script|style|nav|header|footer|iframe|noscript)[^>]*>.*?<\/\1>/si', '', $body);
                // Remover tags HTML
                $body = strip_tags($body);
                // Limpar espaços
                $body = preg_replace('/\s+/', ' ', $body);
                $bodyText = mb_substr(trim($body), 0, 600);
            }

            $parts = [];
            if ($title) $parts[] = "Título: {$title}";
            if ($description) $parts[] = "Descrição: {$description}";
            if ($bodyText) $parts[] = "Conteúdo: {$bodyText}";

            if (empty($parts)) {
                return null;
            }

            return mb_substr(implode('. ', $parts), 0, 800);
        } catch (\Exception $e) {
            Log::debug("fetchUrlContent failed for {$url}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Constrói o prompt de geração de imagem (delega para helper compartilhado)
     */
    private function buildImagePrompt(
        \App\Models\Brand $brand,
        string $topic,
        string $caption,
        SocialPlatform $platform,
        PostType $postType,
        ?string $imageStyle = null,
    ): string {
        return AIGateway::buildSocialImagePrompt(
            $brand, $topic, $caption, $platform->value, $postType->value, $imageStyle
        );
    }

    /**
     * Analisa imagens de referência via GPT-4o Vision e retorna descrição textual
     */
    /**
     * Extrai imagens de referência do request como base64 para envio direto à API de imagem
     * @return array<array{base64: string, mime: string}>
     */
    /**
     * Extrai referências visuais da marca (estilo/cor/composição) como base64 para envio à API de imagem.
     * Política no-logo: o logo NÃO é enviado como referência para não ser renderizado no post.
     */
    private function extractBrandLogoAndReferences(\App\Models\Brand $brand): array
    {
        $images = [];

        $references = $brand->references()->limit(3)->get();
        foreach ($references as $ref) {
            $path = Storage::disk('public')->path($ref->file_path);
            if (!file_exists($path) || filesize($path) > 5 * 1024 * 1024) continue;
            $images[] = [
                'base64' => base64_encode(file_get_contents($path)),
                'mime' => $ref->mime_type ?? 'image/jpeg',
                'role' => 'reference',
            ];
        }

        return $images;
    }

    private function extractReferenceImages(Request $request): array
    {
        $files = $request->file('reference_images');
        if (!$files || empty($files)) return [];

        $images = [];
        foreach ($files as $file) {
            $images[] = [
                'base64' => base64_encode(file_get_contents($file->getRealPath())),
                'mime' => $file->getMimeType(),
            ];
        }
        return $images;
    }

    private function analyzeReferenceImages(Request $request, AIGateway $aiGateway, \App\Models\Brand $brand): ?string
    {
        $files = $request->file('reference_images');
        if (!$files || empty($files)) return null;

        $fileCount = count($files);

        // Se múltiplas imagens, analisar cada uma individualmente para identificar produtos distintos
        if ($fileCount > 1) {
            $descriptions = [];
            foreach ($files as $i => $file) {
                $base64 = base64_encode(file_get_contents($file->getRealPath()));
                $mime = $file->getMimeType();
                $imageContent = [
                    'type' => 'image_url',
                    'image_url' => ['url' => "data:{$mime};base64,{$base64}", 'detail' => 'low'],
                ];

                $messages = [
                    ['role' => 'system', 'content' => 'You analyze a SINGLE product/reference image for social media. Identify the SPECIFIC product shown, describe it in detail: product name/type, colors, packaging, key visual features, brand elements visible. Be precise — this will be used to create an INDIVIDUAL post for THIS specific product. Output in English. Max 150 words.'],
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => "Analyze image " . ($i + 1) . " of {$fileCount}. Describe THIS SPECIFIC product/subject in detail so I can create a SEPARATE, dedicated social media post for it:"],
                        $imageContent,
                    ]],
                ];

                try {
                    $result = $aiGateway->chat(
                        model: AIModel::GPT4o,
                        messages: $messages,
                        brand: $brand,
                        user: $request->user(),
                        feature: 'reference_image_analysis',
                    );
                    $descriptions[] = "PRODUTO/IMAGEM " . ($i + 1) . ": " . ($result['content'] ?? '');
                } catch (\Exception $e) {
                    Log::warning("Reference image {$i} analysis failed: {$e->getMessage()}");
                }
            }

            if (empty($descriptions)) return null;

            return "ATENÇÃO: Foram enviadas {$fileCount} imagens de referência, cada uma com um PRODUTO/ASSUNTO DIFERENTE.\n"
                . "Você DEVE criar posts/sugestões SEPARADOS para CADA produto — NÃO misture todos em uma única imagem ou post.\n"
                . "Cada produto deve ter seu próprio post individual com foco exclusivo nele.\n\n"
                . implode("\n\n", $descriptions);
        }

        // Imagem única — análise padrão
        $base64 = base64_encode(file_get_contents($files[0]->getRealPath()));
        $mime = $files[0]->getMimeType();
        $imageContent = [
            'type' => 'image_url',
            'image_url' => ['url' => "data:{$mime};base64,{$base64}", 'detail' => 'low'],
        ];

        $messages = [
            ['role' => 'system', 'content' => 'You analyze reference images for social media post creation. Describe the visual style, composition, colors, subjects, and mood in detail. Be concise but thorough. Output in English.'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Describe this reference image in detail — style, composition, colors, objects, mood — so I can recreate a similar image with AI:'],
                $imageContent,
            ]],
        ];

        try {
            $result = $aiGateway->chat(
                model: AIModel::GPT4o,
                messages: $messages,
                brand: $brand,
                user: $request->user(),
                feature: 'reference_image_analysis',
            );
            return $result['content'] ?? null;
        } catch (\Exception $e) {
            Log::warning("Reference image analysis failed: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Regenerar imagem de um post existente
     */
    public function regenerateImage(Request $request, Post $post): JsonResponse
    {
        try {
            $brand = $request->user()->getActiveBrand();
            if (!$brand || $post->brand_id !== $brand->id) {
                return response()->json(['error' => 'Acesso negado.'], 403);
            }
            $validated = $request->validate([
                'media_id' => 'nullable|integer|exists:post_media,id',
                'prompt_context' => 'nullable|string|max:2000',
                'image_style' => 'nullable|string|max:200',
                'image_size' => 'nullable|string|in:1024x1024,1792x1024,1024x1792,1536x1024,1024x1536',
                'reference_images' => 'nullable|array|max:3',
                'reference_images.*' => 'image|max:10240',
            ]);
            $aiGateway = app(AIGateway::class);

            $userRefImages = $this->extractReferenceImages($request);
            $referenceContext = $this->analyzeReferenceImages($request, $aiGateway, $brand);

            // Referências de identidade visual da marca (sem logo — política no-logo para posts sociais)
            $brandRefImages = $this->extractBrandLogoAndReferences($brand);
            $allRefImages = array_merge($brandRefImages, $userRefImages);
            $allRefImages = array_slice($allRefImages, 0, 4);

            $topic = $validated['prompt_context'] ?? $post->title ?? mb_substr($post->caption ?? '', 0, 200);
            $platform = SocialPlatform::tryFrom($post->platforms[0] ?? 'instagram') ?? SocialPlatform::Instagram;
            $postType = $post->type instanceof PostType ? $post->type : (PostType::tryFrom($post->type ?? 'feed') ?? PostType::Feed);

            $imagePrompt = $this->buildImagePrompt(
                $brand,
                $topic,
                $post->caption ?? '',
                $platform,
                $postType,
                $validated['image_style'] ?? null,
            );

            if ($validated['prompt_context'] ?? null) {
                $imagePrompt .= "\n\nADDITIONAL CONTEXT: " . $validated['prompt_context'];
            }

            if ($referenceContext) {
                $imagePrompt .= "\n\nREFERENCE IMAGE DESCRIPTION: " . $referenceContext;
            }

            if (!empty($userRefImages)) {
                $imagePrompt .= "\n\nEDIT MODE: You are editing the provided image(s). Keep ALL product details unchanged — logos, labels, text, barcodes, colors, shapes MUST remain pixel-perfect. Only add or change what is explicitly requested. Do NOT regenerate, reimagine, or artistically reinterpret the products.";
            }

            $imageData = $aiGateway->generateImage(
                prompt: $imagePrompt,
                brand: $brand,
                user: $request->user(),
                size: $validated['image_size'] ?? '1024x1024',
                quality: 'auto',
                referenceImages: !empty($allRefImages) ? $allRefImages : null,
            );

            $storagePath = null;
            $finalPath = 'post-media/' . $brand->id . '/' . uniqid('ai-regen-') . '.png';

            if (!empty($imageData['stored_path'])) {
                Storage::disk('public')->copy($imageData['stored_path'], $finalPath);
                Storage::disk('public')->delete($imageData['stored_path']);
                $storagePath = $finalPath;
            } elseif (!empty($imageData['url'])) {
                try {
                    $dlResponse = \Illuminate\Support\Facades\Http::timeout(60)->get($imageData['url']);
                    if ($dlResponse->successful()) {
                        Storage::disk('public')->put($finalPath, $dlResponse->body());
                        $storagePath = $finalPath;
                    }
                } catch (\Throwable $dlErr) {
                    Log::warning("Image download failed: {$dlErr->getMessage()}");
                }
            }

            if (!$storagePath) {
                return response()->json(['error' => 'Falha ao salvar imagem gerada. Tente novamente.'], 500);
            }

            if (!empty($validated['media_id'])) {
                $media = \App\Models\PostMedia::where('id', $validated['media_id'])
                    ->where('post_id', $post->id)
                    ->first();
                if ($media) {
                    if ($media->file_path) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($media->file_path);
                    }
                    $media->update([
                        'file_path' => $storagePath,
                        'file_name' => basename($storagePath),
                    ]);
                }
            } else {
                \App\Models\PostMedia::create([
                    'post_id' => $post->id,
                    'type' => 'image',
                    'file_path' => $storagePath,
                    'file_name' => basename($storagePath),
                    'order' => $post->media()->count(),
                ]);
            }

            return response()->json([
                'success' => true,
                'image_url' => \Illuminate\Support\Facades\Storage::url($storagePath),
                'model' => $imageData['model'] ?? 'unknown',
                'revised_prompt' => mb_substr($imageData['revised_prompt'] ?? '', 0, 200),
                'message' => 'Imagem regenerada com sucesso!',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $msgs = collect($e->errors())->flatten()->implode(' ');
            return response()->json(['error' => $msgs], 422);
        } catch (\Throwable $e) {
            Log::error("regenerateImage error: {$e->getMessage()}", [
                'post_id' => $post->id ?? null,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 2000),
            ]);
            return response()->json(['error' => 'Erro ao regenerar imagem: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Pagina do calendario visual
     */
    public function calendar(): Response
    {
        return Inertia::render('Social/Calendar/Index', [
            'aiModels' => $this->getAIModelOptions(),
        ]);
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
                'date' => ($post->scheduled_at ?? $post->published_at ?? $post->created_at)->setTimezone(config('app.display_timezone'))->format('Y-m-d'),
                'time' => ($post->scheduled_at ?? $post->published_at ?? $post->created_at)->setTimezone(config('app.display_timezone'))->format('H:i'),
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

        // Manter o horario original (no fuso local do usuario), so alterar a data.
        // $validated['date'] e a data local destino; reaplicamos a hora local
        // original e convertemos de volta para UTC para armazenar.
        $tz = config('app.display_timezone');
        $oldDate = ($post->scheduled_at ?? $post->created_at)->copy()->setTimezone($tz);
        $newDate = \Carbon\Carbon::parse($validated['date'], $tz)->setTime(
            $oldDate->hour,
            $oldDate->minute,
            $oldDate->second
        )->utc();

        $post->update([
            'scheduled_at' => $newDate,
            'status' => $post->status === PostStatus::Draft ? PostStatus::Scheduled->value : $post->status->value,
        ]);

        // Datas de volta no fuso local para o calendário posicionar o card.
        $newDateLocal = $newDate->copy()->setTimezone($tz);

        return response()->json([
            'message' => 'Post reagendado para ' . $newDateLocal->format('d/m/Y') . '.',
            'new_date' => $newDateLocal->format('Y-m-d'),
        ]);
    }

    /**
     * Analisa specs do vídeo com ffprobe para diagnóstico de falhas no Instagram.
     */
    public function analyzeMedia(Request $request, Post $post): JsonResponse
    {
        $this->authorizePost($request, $post);

        $results = [];

        foreach ($post->media as $media) {
            $localPath = storage_path('app/public/' . $media->file_path);
            $url       = $media->file_path ? Storage::url($media->file_path) : null;

            $item = [
                'media_id'   => $media->id,
                'type'       => $media->type,
                'mime_type'  => $media->mime_type,
                'file_size'  => $media->file_size,
                'width'      => $media->width,
                'height'     => $media->height,
                'url'        => $url,
                'file_exists' => file_exists($localPath),
            ];

            if ($media->type === 'video' && file_exists($localPath)) {
                $ffprobeCheck = @shell_exec('ffprobe -version 2>&1');
                $hasFfprobe   = $ffprobeCheck && str_contains($ffprobeCheck, 'ffprobe version');

                if ($hasFfprobe) {
                    $cmd    = sprintf('ffprobe -v quiet -print_format json -show_streams -show_format %s 2>&1', escapeshellarg($localPath));
                    $output = shell_exec($cmd);
                    $info   = $output ? json_decode($output, true) : null;

                    if ($info) {
                        $streams     = $info['streams'] ?? [];
                        $format      = $info['format'] ?? [];
                        $videoStream = collect($streams)->firstWhere('codec_type', 'video');
                        $audioStream = collect($streams)->firstWhere('codec_type', 'audio');

                        $width    = (int) ($videoStream['width'] ?? 0);
                        $height   = (int) ($videoStream['height'] ?? 0);
                        $duration = (float) ($format['duration'] ?? $videoStream['duration'] ?? 0);

                        $item['ffprobe'] = [
                            'video_codec' => $videoStream['codec_name'] ?? 'unknown',
                            'audio_codec' => $audioStream['codec_name'] ?? 'none',
                            'width'       => $width,
                            'height'      => $height,
                            'duration_s'  => round($duration, 2),
                            'fps'         => $videoStream['r_frame_rate'] ?? null,
                            'bitrate_kbps'=> isset($format['bit_rate']) ? round((int) $format['bit_rate'] / 1000) : null,
                        ];

                        $issues = [];

                        $vc = strtolower($videoStream['codec_name'] ?? '');
                        if ($vc && !in_array($vc, ['h264', 'avc'])) {
                            $issues[] = "❌ Codec de vídeo: '{$vc}' — precisa ser H.264";
                        } else {
                            $issues[] = "✅ Codec de vídeo: {$vc} (OK)";
                        }

                        $ac = strtolower($audioStream['codec_name'] ?? '');
                        if ($audioStream && $ac && $ac !== 'aac') {
                            $issues[] = "❌ Codec de áudio: '{$ac}' — precisa ser AAC";
                        } elseif ($ac) {
                            $issues[] = "✅ Codec de áudio: {$ac} (OK)";
                        } else {
                            $issues[] = "⚠️ Sem faixa de áudio";
                        }

                        if ($duration < 3) {
                            $issues[] = "❌ Duração: {$duration}s — mínimo 3s para Reels";
                        } elseif ($duration > 180) {
                            $issues[] = "❌ Duração: {$duration}s — máximo 180s para Reels";
                        } else {
                            $issues[] = "✅ Duração: {$duration}s (OK)";
                        }

                        if ($width && $height) {
                            $ratio = $width / $height;
                            if ($ratio > 1.01) {
                                $issues[] = sprintf("⚠️ Proporção: %dx%d landscape (%.2f:1) — Reels preferem 9:16 portrait (1080×1920)", $width, $height, $ratio);
                            } else {
                                $issues[] = "✅ Proporção: {$width}x{$height} portrait (OK)";
                            }

                            if (min($width, $height) < 500) {
                                $issues[] = "❌ Resolução muito baixa — mínimo 500px no menor lado";
                            }
                        }

                        $item['instagram_check'] = $issues;
                    }
                } else {
                    $item['ffprobe'] = null;
                    $item['ffprobe_error'] = 'ffprobe não instalado no servidor';
                }
            }

            $results[] = $item;
        }

        return response()->json([
            'post_id'     => $post->id,
            'post_type'   => $post->type?->value,
            'media_count' => count($results),
            'media'       => $results,
        ]);
    }

    public function syncMetrics(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand) {
            return response()->json(['message' => 'Nenhuma marca ativa.'], 422);
        }

        \App\Jobs\SyncPostMetricsJob::dispatchSync();

        return response()->json(['message' => 'Métricas sincronizadas com sucesso!']);
    }

    // ===== PRIVATE METHODS =====

    private function authorizePost(Request $request, Post $post): void
    {
        // Edição compartilhada: usuário precisa apenas ter acesso a alguma das
        // brands em que o post está vinculado (via pivô post_brand).
        $userBrandIds = $request->user()->brands()->pluck('brands.id')->all();
        $postBrandIds = $post->brands()->pluck('brands.id')->all();

        // Compatibilidade: se o post ainda não foi migrado para a pivô (raro),
        // cai no brand_id legado.
        if (empty($postBrandIds) && $post->brand_id) {
            $postBrandIds = [$post->brand_id];
        }

        if (empty(array_intersect($userBrandIds, $postBrandIds))) {
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

    /**
     * Normaliza vídeo para Instagram Reels.
     * Retorna o path normalizado ou o path original se falhar.
     */
    private function normalizeVideoForReels(\App\Services\Social\VideoNormalizerService $normalizer, string $storagePath): string
    {
        $inputPath = storage_path('app/public/' . $storagePath);
        $specs     = $normalizer->analyze($inputPath);

        if (!$specs || !$normalizer->needsNormalization($specs)) {
            return $storagePath;
        }

        $normalized = $normalizer->normalize($inputPath);

        if ($normalized && $normalized !== $inputPath) {
            // Remover o arquivo original e substituir pelo normalizado
            $dir  = dirname($storagePath);
            $name = pathinfo($normalized, PATHINFO_BASENAME);
            $newStoragePath = $dir . '/' . $name;

            // Mover para o mesmo diretório no storage com nome _reels
            if (file_exists($normalized)) {
                // Deletar original
                @unlink($inputPath);

                // Se o normalizado já está no lugar certo
                if (realpath($normalized) !== realpath(storage_path('app/public/' . $newStoragePath))) {
                    rename($normalized, storage_path('app/public/' . $newStoragePath));
                }

                return $newStoragePath;
            }
        }

        return $storagePath;
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
        $models = collect(AIModel::availableCases())->map(fn($m) => [
            'value' => $m->value,
            'label' => $m->label(),
            'provider' => $m->provider()->label(),
        ])->toArray();

        if (empty($models)) {
            $models = collect(AIModel::cases())->map(fn($m) => [
                'value' => $m->value,
                'label' => $m->label(),
                'provider' => $m->provider()->label(),
            ])->toArray();
        }

        return $models;
    }
}

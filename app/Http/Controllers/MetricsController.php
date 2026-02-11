<?php

namespace App\Http\Controllers;

use App\Models\CustomMetric;
use App\Models\CustomMetricEntry;
use App\Models\MetricCategory;
use App\Models\MetricGoal;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMetricTemplate;
use App\Services\Social\SocialInsightsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MetricsController extends Controller
{
    /**
     * Dashboard de métricas customizadas
     */
    public function index(Request $request): Response
    {
        $brandId = $request->user()->current_brand_id;

        $metrics = CustomMetric::where('brand_id', $brandId)
            ->where('is_active', true)
            ->with('metricCategory')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function ($metric) {
                $latestEntry = $metric->entries()->latest('date')->first();
                $previousEntry = $metric->entries()
                    ->where('date', '<', $latestEntry?->date ?? now())
                    ->latest('date')
                    ->first();

                $variation = null;
                if ($latestEntry && $previousEntry && $previousEntry->value > 0) {
                    $variation = round((($latestEntry->value - $previousEntry->value) / abs($previousEntry->value)) * 100, 1);
                }

                // Meta ativa principal
                $activeGoal = $metric->activeGoals()->where('end_date', '>=', now())->orderBy('end_date')->first();
                $goalProgress = $activeGoal ? $activeGoal->calculateProgress() : $metric->getGoalProgress();

                return [
                    'id' => $metric->id,
                    'name' => $metric->name,
                    'description' => $metric->description,
                    'category' => $metric->metricCategory?->name ?? $metric->category,
                    'category_id' => $metric->metric_category_id,
                    'category_color' => $metric->metricCategory?->color ?? $metric->color,
                    'unit' => $metric->unit,
                    'value_type' => $metric->value_type,
                    'color' => $metric->color,
                    'icon' => $metric->icon,
                    'direction' => $metric->direction ?? 'up',
                    'platform' => $metric->platform,
                    'tags' => $metric->tags ?? [],
                    'tracking_frequency' => $metric->tracking_frequency ?? 'monthly',
                    'custom_frequency_days' => $metric->custom_frequency_days,
                    'custom_start_date' => $metric->custom_start_date?->format('Y-m-d'),
                    'custom_end_date' => $metric->custom_end_date?->format('Y-m-d'),
                    'goal_value' => $activeGoal ? (float) $activeGoal->target_value : (float) $metric->goal_value,
                    'goal_period' => $activeGoal ? $activeGoal->period : $metric->goal_period,
                    'goal_name' => $activeGoal?->name,
                    'goal_days_remaining' => $activeGoal?->daysRemaining(),
                    'goal_time_elapsed' => $activeGoal ? round($activeGoal->timeElapsedPercent(), 1) : null,
                    'latest_value' => $latestEntry ? (float) $latestEntry->value : null,
                    'latest_date' => $latestEntry?->date?->format('d/m/Y'),
                    'formatted_value' => $latestEntry ? $metric->formatValue((float) $latestEntry->value) : '--',
                    'variation' => $variation,
                    'variation_positive' => $metric->isVariationPositive($variation),
                    'goal_progress' => $goalProgress !== null ? round($goalProgress, 1) : null,
                    'entries_count' => $metric->entries()->count(),
                ];
            });

        // Categorias gerenciáveis
        $categories = MetricCategory::where('brand_id', $brandId)
            ->withCount(['metrics' => fn($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'color' => $c->color,
                'icon' => $c->icon,
                'metrics_count' => $c->metrics_count,
            ]);

        // Tags únicas
        $allTags = CustomMetric::getAllTagsForBrand($brandId);

        // Plataformas em uso
        $usedPlatforms = CustomMetric::where('brand_id', $brandId)
            ->whereNotNull('platform')
            ->distinct()
            ->pluck('platform')
            ->toArray();

        // Resumo geral
        $summary = [
            'total_metrics' => $metrics->count(),
            'with_goals' => $metrics->filter(fn($m) => $m['goal_value'] > 0)->count(),
            'on_track' => $metrics->filter(fn($m) => $m['goal_progress'] !== null && $m['goal_progress'] >= ($m['goal_time_elapsed'] ?? 0))->count(),
            'needs_attention' => $metrics->filter(fn($m) => $m['variation'] !== null && !$m['variation_positive'])->count(),
        ];

        return Inertia::render('Metrics/Index', [
            'metrics' => $metrics,
            'categories' => $categories,
            'allTags' => $allTags,
            'usedPlatforms' => $usedPlatforms,
            'availablePlatforms' => CustomMetric::availablePlatforms(),
            'summary' => $summary,
        ]);
    }

    /**
     * Formulário de criação de métrica
     */
    public function create(Request $request): Response
    {
        $brandId = $request->user()->current_brand_id;

        $categories = MetricCategory::where('brand_id', $brandId)
            ->orderBy('name')
            ->get()
            ->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'color' => $c->color]);

        $allTags = CustomMetric::getAllTagsForBrand($brandId);

        // Contas sociais conectadas (para vincular metricas)
        $connectedAccounts = SocialAccount::where('brand_id', $brandId)
            ->where('is_active', true)
            ->get()
            ->map(fn($a) => [
                'id' => $a->id,
                'platform' => $a->platform->value ?? $a->platform,
                'username' => $a->username,
                'display_name' => $a->display_name,
                'avatar_url' => $a->avatar_url,
                'metadata' => $a->metadata,
            ]);

        // Templates de metricas sociais agrupados por plataforma
        $socialTemplates = SocialMetricTemplate::where('is_active', true)
            ->orderBy('platform')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('platform')
            ->map(function ($templates, $platform) {
                return $templates->map(fn($t) => [
                    'id' => $t->id,
                    'platform' => $t->platform,
                    'metric_key' => $t->metric_key,
                    'name' => $t->name,
                    'description' => $t->description,
                    'unit' => $t->unit,
                    'value_prefix' => $t->value_prefix,
                    'value_suffix' => $t->value_suffix,
                    'decimal_places' => $t->decimal_places,
                    'direction' => $t->direction,
                    'aggregation' => $t->aggregation,
                    'color' => $t->color,
                    'icon' => $t->icon,
                    'category' => $t->category,
                ]);
            });

        // Metricas sociais ja vinculadas (para evitar duplicatas)
        $linkedMetrics = CustomMetric::where('brand_id', $brandId)
            ->whereNotNull('social_account_id')
            ->whereNotNull('social_metric_key')
            ->select('social_account_id', 'social_metric_key')
            ->get()
            ->map(fn($m) => $m->social_account_id . ':' . $m->social_metric_key)
            ->toArray();

        return Inertia::render('Metrics/Create', [
            'categories' => $categories,
            'allTags' => $allTags,
            'availablePlatforms' => CustomMetric::availablePlatforms(),
            'connectedAccounts' => $connectedAccounts,
            'socialTemplates' => $socialTemplates,
            'linkedMetrics' => $linkedMetrics,
        ]);
    }

    /**
     * Salva nova métrica
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:100',
            'metric_category_id' => 'nullable|exists:metric_categories,id',
            'unit' => 'required|string|max:50',
            'value_type' => 'nullable|string|max:50',
            'value_prefix' => 'nullable|string|max:20',
            'value_suffix' => 'nullable|string|max:20',
            'decimal_places' => 'nullable|integer|min:0|max:6',
            'direction' => 'nullable|in:up,down,neutral',
            'color' => 'nullable|string|max:7',
            'icon' => 'nullable|string|max:50',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'platform' => 'nullable|string|max:50',
            'tracking_frequency' => 'nullable|string|in:daily,weekly,biweekly,monthly,quarterly,yearly,custom',
            'custom_frequency_days' => 'nullable|integer|min:1|max:365',
            'custom_start_date' => 'nullable|date',
            'custom_end_date' => 'nullable|date|after_or_equal:custom_start_date',
            'aggregation' => 'nullable|in:sum,avg,last,max,min',
            'goal_value' => 'nullable|numeric|min:0',
            'goal_period' => 'nullable|in:monthly,quarterly,yearly',
            'goal_start_date' => 'nullable|date',
            'goal_end_date' => 'nullable|date|after_or_equal:goal_start_date',
            // Nova categoria inline
            'new_category_name' => 'nullable|string|max:100',
            'new_category_color' => 'nullable|string|max:7',
            // Social link
            'social_account_id' => 'nullable|exists:social_accounts,id',
            'social_metric_key' => 'nullable|string|max:50',
            'auto_sync' => 'nullable|boolean',
        ]);

        $brandId = $request->user()->current_brand_id;

        // Criar categoria inline se solicitado
        if (!empty($validated['new_category_name']) && empty($validated['metric_category_id'])) {
            $cat = MetricCategory::create([
                'brand_id' => $brandId,
                'name' => $validated['new_category_name'],
                'color' => $validated['new_category_color'] ?? '#6366F1',
            ]);
            $validated['metric_category_id'] = $cat->id;
            $validated['category'] = $validated['new_category_name'];
        }

        // Limpar campos custom se frequencia nao for custom
        if (($validated['tracking_frequency'] ?? '') !== 'custom') {
            $validated['custom_frequency_days'] = null;
            $validated['custom_start_date'] = null;
            $validated['custom_end_date'] = null;
        }

        // Se tem social link, forcar auto_sync e data_source
        if (!empty($validated['social_account_id']) && !empty($validated['social_metric_key'])) {
            $validated['auto_sync'] = $validated['auto_sync'] ?? true;
            $validated['data_source'] = 'social_api';
            $validated['tracking_frequency'] = 'daily';
        }

        // Separar dados da meta antes de criar a métrica
        $goalStartDate = $validated['goal_start_date'] ?? null;
        $goalEndDate = $validated['goal_end_date'] ?? null;
        unset($validated['new_category_name'], $validated['new_category_color'], $validated['goal_start_date'], $validated['goal_end_date']);

        $validated['brand_id'] = $brandId;
        $validated['user_id'] = $request->user()->id;

        $metric = CustomMetric::create($validated);

        // Criar MetricGoal se tiver meta com datas
        if (!empty($validated['goal_value']) && !empty($validated['goal_period']) && $goalStartDate && $goalEndDate) {
            $metric->goals()->create([
                'name' => 'Meta ' . ucfirst($validated['goal_period']),
                'target_value' => $validated['goal_value'],
                'period' => $validated['goal_period'],
                'start_date' => $goalStartDate,
                'end_date' => $goalEndDate,
                'comparison_type' => 'absolute',
            ]);
        }

        // Se e uma metrica social com auto_sync, importar dados historicos
        if ($metric->auto_sync && $metric->social_account_id) {
            $this->importHistoricalSocialData($metric);
        }

        return redirect()->route('metrics.index')
            ->with('success', 'Metrica criada com sucesso!' . ($metric->auto_sync ? ' Dados historicos importados automaticamente.' : ''));
    }

    /**
     * Detalhe da métrica com gráfico de evolução
     */
    public function show(Request $request, CustomMetric $metric): Response
    {
        $this->authorizeMetric($request, $metric);

        $period = $request->get('period', '6months');
        $startDate = match ($period) {
            '1week' => now()->subWeek(),
            '2weeks' => now()->subWeeks(2),
            '1month' => now()->subMonth(),
            '3months' => now()->subMonths(3),
            '6months' => now()->subMonths(6),
            '1year' => now()->subYear(),
            'all' => null,
            default => now()->subMonths(6),
        };

        $entriesQuery = $metric->entries()->orderBy('date');
        if ($startDate) {
            $entriesQuery->where('date', '>=', $startDate);
        }

        $entries = $entriesQuery->get()->map(fn($e) => [
            'id' => $e->id,
            'value' => (float) $e->value,
            'date' => $e->date->format('Y-m-d'),
            'date_formatted' => $e->date->format('d/m/Y'),
            'notes' => $e->notes,
            'source' => $e->source ?? 'manual',
        ]);

        // Dados para comparação com período anterior
        $comparison = $this->getComparison($metric, $period);

        // Metas
        $goals = $metric->goals()
            ->orderBy('is_active', 'desc')
            ->orderBy('end_date', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'target_value' => (float) $g->target_value,
                'target_formatted' => $metric->formatValue((float) $g->target_value),
                'period' => $g->period,
                'start_date' => $g->start_date->format('d/m/Y'),
                'end_date' => $g->end_date->format('d/m/Y'),
                'baseline_value' => $g->baseline_value ? (float) $g->baseline_value : null,
                'comparison_type' => $g->comparison_type,
                'notes' => $g->notes,
                'is_active' => $g->is_active,
                'achieved' => $g->achieved,
                'progress' => round($g->calculateProgress() ?? 0, 1),
                'days_remaining' => $g->daysRemaining(),
                'time_elapsed' => round($g->timeElapsedPercent(), 1),
                'is_expired' => $g->isExpired(),
            ]);

        // Estatísticas avançadas
        $allEntries = $metric->entries()->orderBy('date')->get();
        $stats = $this->calculateAdvancedStats($allEntries, $metric);

        return Inertia::render('Metrics/Show', [
            'metric' => [
                'id' => $metric->id,
                'name' => $metric->name,
                'description' => $metric->description,
                'category' => $metric->metricCategory?->name ?? $metric->category,
                'unit' => $metric->unit,
                'value_type' => $metric->value_type,
                'value_prefix' => $metric->value_prefix,
                'value_suffix' => $metric->value_suffix,
                'decimal_places' => $metric->decimal_places,
                'direction' => $metric->direction,
                'color' => $metric->color,
                'platform' => $metric->platform,
                'tags' => $metric->tags ?? [],
                'tracking_frequency' => $metric->tracking_frequency,
                'custom_frequency_days' => $metric->custom_frequency_days,
                'custom_start_date' => $metric->custom_start_date?->format('Y-m-d'),
                'custom_end_date' => $metric->custom_end_date?->format('Y-m-d'),
                'goal_value' => $metric->goal_value,
                'goal_period' => $metric->goal_period,
                'goal_progress' => $metric->getGoalProgress(),
            ],
            'entries' => $entries,
            'comparison' => $comparison,
            'goals' => $goals,
            'stats' => $stats,
            'period' => $period,
        ]);
    }

    /**
     * Editar métrica
     */
    public function edit(Request $request, CustomMetric $metric): Response
    {
        $this->authorizeMetric($request, $metric);
        $brandId = $request->user()->current_brand_id;

        $categories = MetricCategory::where('brand_id', $brandId)
            ->orderBy('name')
            ->get()
            ->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'color' => $c->color]);

        $allTags = CustomMetric::getAllTagsForBrand($brandId);

        return Inertia::render('Metrics/Edit', [
            'metric' => $metric,
            'categories' => $categories,
            'allTags' => $allTags,
            'availablePlatforms' => CustomMetric::availablePlatforms(),
        ]);
    }

    /**
     * Atualizar métrica
     */
    public function update(Request $request, CustomMetric $metric): RedirectResponse
    {
        $this->authorizeMetric($request, $metric);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:100',
            'metric_category_id' => 'nullable|exists:metric_categories,id',
            'unit' => 'required|string|max:50',
            'value_type' => 'nullable|string|max:50',
            'value_prefix' => 'nullable|string|max:20',
            'value_suffix' => 'nullable|string|max:20',
            'decimal_places' => 'nullable|integer|min:0|max:6',
            'direction' => 'nullable|in:up,down,neutral',
            'color' => 'nullable|string|max:7',
            'icon' => 'nullable|string|max:50',
            'tags' => 'nullable|array',
            'platform' => 'nullable|string|max:50',
            'tracking_frequency' => 'nullable|string|in:daily,weekly,biweekly,monthly,quarterly,yearly,custom',
            'custom_frequency_days' => 'nullable|integer|min:1|max:365',
            'custom_start_date' => 'nullable|date',
            'custom_end_date' => 'nullable|date|after_or_equal:custom_start_date',
            'aggregation' => 'nullable|in:sum,avg,last,max,min',
            'goal_value' => 'nullable|numeric|min:0',
            'goal_period' => 'nullable|in:monthly,quarterly,yearly',
        ]);

        // Limpar campos custom se frequencia nao for custom
        if (($validated['tracking_frequency'] ?? '') !== 'custom') {
            $validated['custom_frequency_days'] = null;
            $validated['custom_start_date'] = null;
            $validated['custom_end_date'] = null;
        }

        $metric->update($validated);

        return redirect()->route('metrics.show', $metric->id)
            ->with('success', 'Metrica atualizada com sucesso!');
    }

    /**
     * Adiciona uma entrada de valor
     */
    public function addEntry(Request $request, CustomMetric $metric): RedirectResponse
    {
        $this->authorizeMetric($request, $metric);

        $validated = $request->validate([
            'value' => 'required|numeric',
            'date' => 'required|date',
            'notes' => 'nullable|string|max:500',
            'tags' => 'nullable|array',
        ]);

        $metric->entries()->updateOrCreate(
            ['date' => $validated['date'], 'custom_metric_id' => $metric->id],
            [
                'value' => $validated['value'],
                'notes' => $validated['notes'] ?? null,
                'tags' => $validated['tags'] ?? null,
                'user_id' => $request->user()->id,
                'source' => 'manual',
            ]
        );

        // Verificar metas atingidas
        $this->checkGoalAchievement($metric);

        return redirect()->back()
            ->with('success', 'Valor registrado com sucesso!');
    }

    /**
     * Remove uma entrada
     */
    public function removeEntry(Request $request, CustomMetric $metric, CustomMetricEntry $entry): RedirectResponse
    {
        $this->authorizeMetric($request, $metric);
        $entry->delete();

        return redirect()->back()
            ->with('success', 'Entrada removida.');
    }

    /**
     * API para dados do gráfico
     */
    public function chartData(Request $request, CustomMetric $metric): JsonResponse
    {
        $this->authorizeMetric($request, $metric);

        $entries = $metric->entries()
            ->orderBy('date')
            ->get()
            ->map(fn($e) => [
                'date' => $e->date->format('Y-m-d'),
                'value' => (float) $e->value,
            ]);

        return response()->json([
            'metric' => $metric->only('id', 'name', 'unit', 'color', 'goal_value', 'direction'),
            'entries' => $entries,
        ]);
    }

    public function destroy(Request $request, CustomMetric $metric): RedirectResponse
    {
        $this->authorizeMetric($request, $metric);
        $metric->delete();

        return redirect()->route('metrics.index')
            ->with('success', 'Metrica removida.');
    }

    // ===== CATEGORIAS =====

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'nullable|string|max:7',
            'icon' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
        ]);

        $validated['brand_id'] = $request->user()->current_brand_id;

        MetricCategory::create($validated);

        return back()->with('success', 'Categoria criada com sucesso!');
    }

    public function updateCategory(Request $request, MetricCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'nullable|string|max:7',
            'description' => 'nullable|string|max:500',
        ]);

        $category->update($validated);

        return back()->with('success', 'Categoria atualizada.');
    }

    public function destroyCategory(Request $request, MetricCategory $category): RedirectResponse
    {
        if ($category->brand_id !== $request->user()->current_brand_id) {
            abort(403);
        }

        $category->delete();

        return back()->with('success', 'Categoria removida.');
    }

    // ===== METAS =====

    public function storeGoal(Request $request, CustomMetric $metric): RedirectResponse
    {
        $this->authorizeMetric($request, $metric);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'target_value' => 'required|numeric|min:0',
            'period' => 'required|in:weekly,monthly,quarterly,semester,yearly,custom',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'baseline_value' => 'nullable|numeric',
            'comparison_type' => 'nullable|in:absolute,percentage,cumulative',
            'notes' => 'nullable|string|max:500',
        ]);

        $metric->goals()->create($validated);

        return back()->with('success', 'Meta criada com sucesso!');
    }

    public function updateGoal(Request $request, MetricGoal $goal): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'target_value' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        $goal->update($validated);

        return back()->with('success', 'Meta atualizada.');
    }

    public function destroyGoal(Request $request, MetricGoal $goal): RedirectResponse
    {
        $goal->delete();

        return back()->with('success', 'Meta removida.');
    }

    // ===== SOCIAL INSIGHTS =====

    /**
     * Sincronizar insights de uma conta social manualmente
     */
    public function syncSocialInsights(Request $request): RedirectResponse
    {
        $brandId = $request->user()->current_brand_id;
        $accountId = $request->get('account_id');

        $service = app(SocialInsightsService::class);

        if ($accountId) {
            $account = SocialAccount::where('id', $accountId)
                ->where('brand_id', $brandId)
                ->firstOrFail();

            $result = $service->syncAccount($account);

            if ($result) {
                // Auto-sync metricas vinculadas
                $this->syncLinkedMetrics($account);
                return back()->with('success', "Insights de {$account->display_name} sincronizados!");
            } else {
                return back()->with('error', "Falha ao sincronizar insights de {$account->display_name}.");
            }
        }

        // Sync todas as contas da brand
        $results = $service->syncBrand($brandId);
        $success = count(array_filter($results));
        $total = count($results);

        // Auto-sync todas metricas vinculadas
        $accounts = SocialAccount::where('brand_id', $brandId)->where('is_active', true)->get();
        foreach ($accounts as $account) {
            $this->syncLinkedMetrics($account);
        }

        return back()->with('success', "{$success}/{$total} contas sincronizadas com sucesso!");
    }

    /**
     * API: Buscar insights de uma conta (para graficos no frontend)
     */
    public function socialInsightsData(Request $request, SocialAccount $account): JsonResponse
    {
        if ($account->brand_id !== $request->user()->current_brand_id) {
            abort(403);
        }

        $period = $request->get('period', '30');
        $startDate = now()->subDays((int) $period);

        $insights = SocialInsight::where('social_account_id', $account->id)
            ->where('date', '>=', $startDate)
            ->orderBy('date')
            ->get()
            ->map(fn($i) => [
                'date' => $i->date->format('Y-m-d'),
                'date_formatted' => $i->date->format('d/m/Y'),
                'followers_count' => $i->followers_count,
                'following_count' => $i->following_count,
                'posts_count' => $i->posts_count,
                'impressions' => $i->impressions,
                'reach' => $i->reach,
                'engagement' => $i->engagement,
                'engagement_rate' => $i->engagement_rate,
                'likes' => $i->likes,
                'comments' => $i->comments,
                'shares' => $i->shares,
                'saves' => $i->saves,
                'clicks' => $i->clicks,
                'video_views' => $i->video_views,
                'net_followers' => $i->net_followers,
                'platform_data' => $i->platform_data,
            ]);

        // Ultimo insight para resumo
        $latest = $insights->last();

        return response()->json([
            'account' => [
                'id' => $account->id,
                'platform' => $account->platform->value ?? $account->platform,
                'display_name' => $account->display_name,
                'username' => $account->username,
            ],
            'insights' => $insights,
            'latest' => $latest,
            'period' => $period,
        ]);
    }

    /**
     * Criar multiplas metricas de uma vez a partir de templates sociais
     */
    public function createFromTemplates(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'social_account_id' => 'required|exists:social_accounts,id',
            'template_ids' => 'required|array|min:1',
            'template_ids.*' => 'exists:social_metric_templates,id',
            'auto_sync' => 'boolean',
        ]);

        $brandId = $request->user()->current_brand_id;
        $account = SocialAccount::where('id', $validated['social_account_id'])
            ->where('brand_id', $brandId)
            ->firstOrFail();

        $templates = SocialMetricTemplate::whereIn('id', $validated['template_ids'])->get();
        $created = 0;
        $skipped = 0;

        foreach ($templates as $template) {
            // Verificar se ja existe
            $exists = CustomMetric::where('brand_id', $brandId)
                ->where('social_account_id', $account->id)
                ->where('social_metric_key', $template->metric_key)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            $metric = CustomMetric::create([
                'brand_id' => $brandId,
                'user_id' => $request->user()->id,
                'name' => $template->name . ' - ' . $account->display_name,
                'description' => $template->description,
                'category' => $template->category,
                'unit' => $template->unit,
                'value_prefix' => $template->value_prefix,
                'value_suffix' => $template->value_suffix,
                'decimal_places' => $template->decimal_places,
                'direction' => $template->direction,
                'aggregation' => $template->aggregation,
                'color' => $template->color,
                'icon' => $template->icon,
                'platform' => $template->platform,
                'data_source' => 'social_api',
                'tracking_frequency' => 'daily',
                'social_account_id' => $account->id,
                'social_metric_key' => $template->metric_key,
                'auto_sync' => $validated['auto_sync'] ?? true,
            ]);

            // Importar dados historicos
            $this->importHistoricalSocialData($metric);
            $created++;
        }

        $msg = "{$created} metrica(s) criada(s) com sucesso!";
        if ($skipped > 0) {
            $msg .= " ({$skipped} ja existiam e foram ignoradas)";
        }

        return redirect()->route('metrics.index')->with('success', $msg);
    }

    /**
     * Sync metricas vinculadas a uma conta
     */
    private function syncLinkedMetrics(SocialAccount $account): void
    {
        $metrics = CustomMetric::where('social_account_id', $account->id)
            ->where('auto_sync', true)
            ->where('is_active', true)
            ->get();

        foreach ($metrics as $metric) {
            $this->importHistoricalSocialData($metric);
        }
    }

    /**
     * Importar dados historicos de insights para entries de uma metrica
     */
    private function importHistoricalSocialData(CustomMetric $metric): void
    {
        if (!$metric->social_account_id || !$metric->social_metric_key) {
            return;
        }

        $insights = SocialInsight::where('social_account_id', $metric->social_account_id)
            ->where('sync_status', 'success')
            ->orderBy('date')
            ->get();

        foreach ($insights as $insight) {
            $value = $insight->getMetricValue($metric->social_metric_key);

            if ($value === null) {
                continue;
            }

            $metric->entries()->updateOrCreate(
                [
                    'date' => $insight->date->format('Y-m-d'),
                    'custom_metric_id' => $metric->id,
                ],
                [
                    'value' => (float) $value,
                    'user_id' => $metric->user_id,
                    'source' => 'social_sync',
                    'metadata' => [
                        'social_insight_id' => $insight->id,
                        'synced_at' => now()->toIso8601String(),
                    ],
                ]
            );
        }

        $metric->update(['last_synced_at' => now()]);
    }

    // ===== PRIVATE =====

    private function authorizeMetric(Request $request, CustomMetric $metric): void
    {
        if ($metric->brand_id !== $request->user()->current_brand_id) {
            abort(403, 'Acesso negado.');
        }
    }

    private function checkGoalAchievement(CustomMetric $metric): void
    {
        $activeGoals = $metric->activeGoals()->where('end_date', '>=', now())->get();

        foreach ($activeGoals as $goal) {
            $progress = $goal->calculateProgress();
            if ($progress !== null && $progress >= 100 && !$goal->achieved) {
                $goal->update([
                    'achieved' => true,
                    'achieved_at' => now(),
                ]);
            }
        }
    }

    private function getComparison(CustomMetric $metric, string $period): array
    {
        $days = match ($period) {
            '1week' => 7,
            '2weeks' => 14,
            '1month' => 30,
            '3months' => 90,
            '6months' => 180,
            '1year' => 365,
            default => 180,
        };

        $currentEnd = now();
        $currentStart = now()->subDays($days);
        $previousEnd = $currentStart->copy();
        $previousStart = $previousEnd->copy()->subDays($days);

        $currentEntries = $metric->entries()
            ->whereBetween('date', [$currentStart, $currentEnd])
            ->get();

        $previousEntries = $metric->entries()
            ->whereBetween('date', [$previousStart, $previousEnd])
            ->get();

        $currentAvg = $currentEntries->avg('value') ?? 0;
        $previousAvg = $previousEntries->avg('value') ?? 0;

        $currentSum = $currentEntries->sum('value');
        $previousSum = $previousEntries->sum('value');

        $variation = $previousAvg > 0
            ? round((($currentAvg - $previousAvg) / abs($previousAvg)) * 100, 1)
            : null;

        return [
            'current_avg' => round($currentAvg, 2),
            'previous_avg' => round($previousAvg, 2),
            'current_sum' => round($currentSum, 2),
            'previous_sum' => round($previousSum, 2),
            'current_count' => $currentEntries->count(),
            'previous_count' => $previousEntries->count(),
            'current_min' => $currentEntries->count() > 0 ? round($currentEntries->min('value'), 2) : 0,
            'current_max' => $currentEntries->count() > 0 ? round($currentEntries->max('value'), 2) : 0,
            'variation' => $variation,
            'variation_positive' => $metric->isVariationPositive($variation),
        ];
    }

    private function calculateAdvancedStats($entries, CustomMetric $metric): array
    {
        if ($entries->isEmpty()) {
            return [
                'total_entries' => 0,
                'first_date' => null,
                'last_date' => null,
                'all_time_min' => null,
                'all_time_max' => null,
                'all_time_avg' => null,
                'all_time_sum' => null,
                'trend' => null,
                'streak_days' => 0,
            ];
        }

        $values = $entries->pluck('value')->map(fn($v) => (float) $v);

        // Tendência (linear regression simplificada)
        $trend = null;
        if ($entries->count() >= 3) {
            $lastThird = $values->slice(-intval($values->count() / 3));
            $firstThird = $values->slice(0, intval($values->count() / 3));
            $lastAvg = $lastThird->avg();
            $firstAvg = $firstThird->avg();
            if ($firstAvg > 0) {
                $trendPct = round((($lastAvg - $firstAvg) / abs($firstAvg)) * 100, 1);
                $trend = [
                    'direction' => $trendPct > 0 ? 'up' : ($trendPct < 0 ? 'down' : 'stable'),
                    'percentage' => $trendPct,
                    'positive' => $metric->isVariationPositive($trendPct),
                ];
            }
        }

        return [
            'total_entries' => $entries->count(),
            'first_date' => $entries->first()?->date?->format('d/m/Y'),
            'last_date' => $entries->last()?->date?->format('d/m/Y'),
            'all_time_min' => round($values->min(), 2),
            'all_time_max' => round($values->max(), 2),
            'all_time_avg' => round($values->avg(), 2),
            'all_time_sum' => round($values->sum(), 2),
            'all_time_min_formatted' => $metric->formatValue($values->min()),
            'all_time_max_formatted' => $metric->formatValue($values->max()),
            'all_time_avg_formatted' => $metric->formatValue($values->avg()),
            'trend' => $trend,
            'streak_days' => 0, // TODO: calcular streak de registros consecutivos
        ];
    }
}

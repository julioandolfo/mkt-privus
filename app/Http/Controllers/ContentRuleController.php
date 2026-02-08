<?php

namespace App\Http\Controllers;

use App\Models\ContentRule;
use App\Services\Social\ContentEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContentRuleController extends Controller
{
    /**
     * Lista todas as pautas da marca ativa
     */
    public function index(Request $request): Response
    {
        $brand = $request->user()->getActiveBrand();

        $rules = $brand
            ? $brand->contentRules()
                ->withCount('suggestions')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn($rule) => $this->formatRule($rule))
            : collect();

        return Inertia::render('Social/ContentEngine/Rules', [
            'rules' => $rules,
        ]);
    }

    /**
     * Cria nova pauta
     */
    public function store(Request $request): RedirectResponse
    {
        $brand = $request->user()->getActiveBrand();
        if (!$brand) {
            return redirect()->back()->withErrors(['brand' => 'Selecione uma marca.']);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category' => 'required|string|max:100',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string|in:instagram,facebook,linkedin,tiktok,youtube,pinterest',
            'post_type' => 'required|string|max:50',
            'tone_override' => 'nullable|string|max:255',
            'instructions' => 'nullable|string|max:2000',
            'frequency' => 'required|string|in:daily,weekday,weekly,biweekly,monthly',
            'preferred_times' => 'nullable|array',
            'preferred_times.*' => 'string',
        ]);

        $validated['brand_id'] = $brand->id;
        $validated['next_generation_at'] = now(); // Gerar na proxima execucao

        ContentRule::create($validated);

        return redirect()->back()->with('success', 'Pauta criada com sucesso!');
    }

    /**
     * Atualiza uma pauta
     */
    public function update(Request $request, ContentRule $rule): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category' => 'required|string|max:100',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string|in:instagram,facebook,linkedin,tiktok,youtube,pinterest',
            'post_type' => 'required|string|max:50',
            'tone_override' => 'nullable|string|max:255',
            'instructions' => 'nullable|string|max:2000',
            'frequency' => 'required|string|in:daily,weekday,weekly,biweekly,monthly',
            'preferred_times' => 'nullable|array',
            'preferred_times.*' => 'string',
        ]);

        $rule->update($validated);

        return redirect()->back()->with('success', 'Pauta atualizada!');
    }

    /**
     * Remove uma pauta
     */
    public function destroy(ContentRule $rule): RedirectResponse
    {
        $rule->delete();

        return redirect()->back()->with('success', 'Pauta removida.');
    }

    /**
     * Ativa/desativa uma pauta
     */
    public function toggle(ContentRule $rule): RedirectResponse
    {
        $rule->update(['is_active' => !$rule->is_active]);

        $status = $rule->is_active ? 'ativada' : 'desativada';
        return redirect()->back()->with('success', "Pauta {$status}!");
    }

    /**
     * Gera conteudo manualmente a partir de uma pauta
     */
    public function generate(Request $request, ContentRule $rule, ContentEngineService $engine): JsonResponse
    {
        $suggestion = $engine->generateFromRule($rule);

        if ($suggestion) {
            $rule->markAsGenerated();
            return response()->json([
                'success' => true,
                'message' => 'Sugestão gerada com sucesso!',
                'suggestion_id' => $suggestion->id,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao gerar conteúdo. Verifique as configurações de IA.',
        ], 500);
    }

    // ===== PRIVATE =====

    private function formatRule(ContentRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'description' => $rule->description,
            'category' => $rule->category,
            'category_label' => $rule->categoryLabel(),
            'platforms' => $rule->platforms,
            'post_type' => $rule->post_type,
            'tone_override' => $rule->tone_override,
            'instructions' => $rule->instructions,
            'frequency' => $rule->frequency,
            'frequency_label' => $rule->frequencyLabel(),
            'preferred_times' => $rule->preferred_times,
            'is_active' => $rule->is_active,
            'last_generated_at' => $rule->last_generated_at?->format('d/m/Y H:i'),
            'next_generation_at' => $rule->next_generation_at?->format('d/m/Y H:i'),
            'suggestions_count' => $rule->suggestions_count ?? 0,
        ];
    }
}

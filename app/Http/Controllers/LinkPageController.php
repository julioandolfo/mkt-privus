<?php

namespace App\Http\Controllers;

use App\Models\LinkClick;
use App\Models\LinkPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LinkPageController extends Controller
{
    /**
     * Lista de link pages
     */
    public function index(Request $request): Response
    {
        $brandId = session('current_brand_id');

        $pages = LinkPage::forBrand($brandId)
            ->with('user:id,name')
            ->withCount('clicks')
            ->latest()
            ->paginate(12)
            ->through(fn($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'slug' => $p->slug,
                'description' => $p->description,
                'avatar_path' => $p->avatar_path,
                'public_url' => $p->public_url,
                'is_active' => $p->is_active,
                'block_count' => $p->block_count,
                'total_views' => $p->total_views,
                'total_clicks' => $p->total_clicks,
                'theme' => $p->theme,
                'created_at' => $p->created_at->format('d/m/Y'),
                'user_name' => $p->user?->name,
            ]);

        return Inertia::render('Links/Index', [
            'pages' => $pages,
        ]);
    }

    /**
     * Criar nova link page
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:100',
            'description' => 'nullable|string|max:300',
        ]);

        $brandId = session('current_brand_id');

        $page = LinkPage::create([
            'brand_id' => $brandId,
            'user_id' => Auth::id(),
            'title' => $validated['title'],
            'slug' => LinkPage::generateUniqueSlug($validated['title']),
            'description' => $validated['description'] ?? '',
            'blocks' => LinkPage::defaultBlocks(),
            'theme' => [
                'bg_color' => '#0f172a',
                'text_color' => '#ffffff',
                'button_color' => '#4f46e5',
                'button_text_color' => '#ffffff',
                'button_style' => 'rounded',
                'font_family' => 'Inter',
            ],
        ]);

        return redirect()->route('links.editor', $page)->with('success', 'Página criada! Configure seus blocos.');
    }

    /**
     * Editor visual da link page
     */
    public function editor(LinkPage $page): Response
    {
        return Inertia::render('Links/Editor', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'description' => $page->description,
                'avatar_path' => $page->avatar_path,
                'theme' => $page->getThemeDefaults(),
                'blocks' => $page->blocks ?? [],
                'seo_title' => $page->seo_title,
                'seo_description' => $page->seo_description,
                'seo_image' => $page->seo_image,
                'custom_css' => $page->custom_css,
                'is_active' => $page->is_active,
                'public_url' => $page->public_url,
            ],
        ]);
    }

    /**
     * Salvar alterações no editor (AJAX)
     */
    public function save(Request $request, LinkPage $page): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:100',
            'slug' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:300',
            'avatar_path' => 'nullable|string|max:500',
            'theme' => 'nullable|array',
            'blocks' => 'nullable|array',
            'seo_title' => 'nullable|string|max:100',
            'seo_description' => 'nullable|string|max:300',
            'seo_image' => 'nullable|string|max:500',
            'custom_css' => 'nullable|string|max:10000',
            'is_active' => 'nullable|boolean',
        ]);

        // Verificar slug único
        if (!empty($validated['slug']) && $validated['slug'] !== $page->slug) {
            $slugExists = LinkPage::where('slug', $validated['slug'])
                ->where('id', '!=', $page->id)
                ->exists();

            if ($slugExists) {
                return response()->json(['success' => false, 'error' => 'Este slug já está em uso.'], 422);
            }
        }

        $page->update(array_filter($validated, fn($v) => $v !== null));

        return response()->json([
            'success' => true,
            'message' => 'Página salva!',
            'public_url' => $page->public_url,
        ]);
    }

    /**
     * Analytics da link page
     */
    public function analytics(LinkPage $page, Request $request): Response
    {
        $period = $request->input('period', '7d');
        $startDate = match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => now()->subDays(7),
        };

        // Clicks por dia
        $clicksByDay = LinkClick::where('link_page_id', $page->id)
            ->where('clicked_at', '>=', $startDate)
            ->selectRaw("DATE(clicked_at) as date, COUNT(*) as clicks")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'clicks' => $r->clicks])
            ->toArray();

        // Top blocos clicados
        $topBlocks = LinkClick::where('link_page_id', $page->id)
            ->where('clicked_at', '>=', $startDate)
            ->selectRaw("block_index, block_label, block_type, COUNT(*) as clicks")
            ->groupBy('block_index', 'block_label', 'block_type')
            ->orderByDesc('clicks')
            ->limit(20)
            ->get()
            ->map(fn($r) => [
                'block_index' => $r->block_index,
                'block_label' => $r->block_label,
                'block_type' => $r->block_type,
                'clicks' => $r->clicks,
            ])
            ->toArray();

        // Dispositivos
        $devices = LinkClick::where('link_page_id', $page->id)
            ->where('clicked_at', '>=', $startDate)
            ->whereNotNull('device')
            ->selectRaw("device, COUNT(*) as total")
            ->groupBy('device')
            ->get()
            ->pluck('total', 'device')
            ->toArray();

        // Referrers
        $referrers = LinkClick::where('link_page_id', $page->id)
            ->where('clicked_at', '>=', $startDate)
            ->whereNotNull('referer')
            ->where('referer', '!=', '')
            ->selectRaw("SUBSTRING_INDEX(SUBSTRING_INDEX(referer, '/', 3), '://', -1) as source, COUNT(*) as total")
            ->groupBy('source')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn($r) => ['source' => $r->source, 'total' => $r->total])
            ->toArray();

        return Inertia::render('Links/Analytics', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'public_url' => $page->public_url,
                'total_views' => $page->total_views,
                'total_clicks' => $page->total_clicks,
            ],
            'period' => $period,
            'clicksByDay' => $clicksByDay,
            'topBlocks' => $topBlocks,
            'devices' => $devices,
            'referrers' => $referrers,
        ]);
    }

    /**
     * Upload de avatar
     */
    public function uploadAvatar(Request $request, LinkPage $page): JsonResponse
    {
        $request->validate(['image' => 'required|image|max:5120']);

        try {
            $path = $request->file('image')->store('link-avatars', 'public');
            $page->update(['avatar_path' => $path]);

            return response()->json([
                'success' => true,
                'path' => $path,
                'url' => \Illuminate\Support\Facades\Storage::disk('public')->url($path),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Duplicar página
     */
    public function duplicate(LinkPage $page): RedirectResponse
    {
        $newPage = $page->replicate();
        $newPage->title = $page->title . ' (Cópia)';
        $newPage->slug = LinkPage::generateUniqueSlug($newPage->title);
        $newPage->total_views = 0;
        $newPage->total_clicks = 0;
        $newPage->save();

        return redirect()->route('links.editor', $newPage)->with('success', 'Página duplicada!');
    }

    /**
     * Excluir página
     */
    public function destroy(LinkPage $page): RedirectResponse
    {
        $page->delete();
        return redirect()->route('links.index')->with('success', 'Página excluída.');
    }
}

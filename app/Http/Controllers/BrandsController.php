<?php

namespace App\Http\Controllers;

use App\Enums\BrandRole;
use App\Models\Brand;
use App\Models\BrandAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BrandsController extends Controller
{
    public function index(Request $request): Response
    {
        $brands = $request->user()->brands()
            ->withCount('posts', 'socialAccounts')
            ->orderBy('name')
            ->get();

        return Inertia::render('Brands/Index', [
            'brands' => $brands,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Brands/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|url|max:255',
            'segment' => 'nullable|string|max:255',
            'target_audience' => 'nullable|string|max:1000',
            'tone_of_voice' => 'nullable|string|max:255',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'accent_color' => 'nullable|string|max:7',
            'keywords' => 'nullable|array',
            'keywords.*' => 'string|max:100',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $brand = Brand::create($validated);

        // Vincular usuario como owner
        $brand->users()->attach($request->user()->id, [
            'role' => BrandRole::Owner->value,
        ]);

        // Definir como marca ativa
        $request->user()->switchBrand($brand);

        return redirect()->route('brands.index')
            ->with('success', 'Marca criada com sucesso!');
    }

    public function edit(Brand $brand): Response
    {
        $brand->load(['assets' => fn($q) => $q->orderBy('category')->orderBy('sort_order')]);

        return Inertia::render('Brands/Edit', [
            'brand' => $brand,
        ]);
    }

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|url|max:255',
            'segment' => 'nullable|string|max:255',
            'target_audience' => 'nullable|string|max:1000',
            'tone_of_voice' => 'nullable|string|max:255',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'accent_color' => 'nullable|string|max:7',
            'keywords' => 'nullable|array',
            'keywords.*' => 'string|max:100',
            'ai_context' => 'nullable|string|max:2000',
        ]);

        $brand->update($validated);

        return redirect()->route('brands.index')
            ->with('success', 'Marca atualizada com sucesso!');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        // Deletar assets do storage
        $brand->assets()->get()->each(function ($asset) {
            if ($asset->file_path) {
                Storage::disk('public')->delete($asset->file_path);
            }
        });

        $brand->delete();

        return redirect()->route('brands.index')
            ->with('success', 'Marca removida com sucesso!');
    }

    /**
     * Troca a marca ativa do usuario
     */
    public function switchBrand(Request $request, Brand $brand): RedirectResponse
    {
        $request->user()->switchBrand($brand);

        return redirect()->back()
            ->with('success', "Marca alterada para {$brand->name}");
    }

    // ===== BRAND ASSETS =====

    /**
     * Upload de asset para a marca
     */
    public function uploadAsset(Request $request, Brand $brand): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,jpg,png,gif,webp,svg|max:10240', // 10MB
            'category' => 'required|string|in:logo,icon,watermark,reference',
            'label' => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        $category = $request->input('category');
        $label = $request->input('label', $file->getClientOriginalName());

        // Salvar arquivo
        $path = $file->store("brands/{$brand->id}/assets", 'public');

        // Obter dimensoes da imagem
        $dimensions = null;
        try {
            $imageSize = getimagesize($file->getPathname());
            if ($imageSize) {
                $dimensions = ['width' => $imageSize[0], 'height' => $imageSize[1]];
            }
        } catch (\Exception $e) {
            // Ignora erro de dimensoes (SVG por exemplo)
        }

        // Calcular sort_order
        $maxOrder = $brand->assets()->where('category', $category)->max('sort_order') ?? 0;

        $asset = $brand->assets()->create([
            'category' => $category,
            'label' => $label,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'dimensions' => $dimensions,
            'is_primary' => $brand->assets()->where('category', $category)->count() === 0,
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json([
            'success' => true,
            'asset' => $asset->fresh(),
        ]);
    }

    /**
     * Remover asset da marca
     */
    public function deleteAsset(Brand $brand, BrandAsset $asset): JsonResponse
    {
        if ($asset->brand_id !== $brand->id) {
            abort(403);
        }

        // Deletar arquivo do storage
        if ($asset->file_path) {
            Storage::disk('public')->delete($asset->file_path);
        }

        $wasPrimary = $asset->is_primary;
        $category = $asset->category;

        $asset->delete();

        // Se era primario, promover o proximo
        if ($wasPrimary) {
            $next = $brand->assets()->where('category', $category)->first();
            if ($next) {
                $next->update(['is_primary' => true]);
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * Definir asset como primario
     */
    public function setPrimaryAsset(Brand $brand, BrandAsset $asset): JsonResponse
    {
        if ($asset->brand_id !== $brand->id) {
            abort(403);
        }

        // Remover primary de todos da mesma categoria
        $brand->assets()
            ->where('category', $asset->category)
            ->update(['is_primary' => false]);

        $asset->update(['is_primary' => true]);

        return response()->json(['success' => true]);
    }
}

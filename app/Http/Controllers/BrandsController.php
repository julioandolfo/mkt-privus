<?php

namespace App\Http\Controllers;

use App\Enums\BrandRole;
use App\Models\Brand;
use App\Models\BrandAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        Log::info('BrandsController@store: Iniciando criação de marca', [
            'user_id' => $request->user()->id,
            'input_keys' => array_keys($request->all()),
            'has_urls' => $request->has('urls'),
            'urls_count' => is_array($request->input('urls')) ? count($request->input('urls')) : 'not_array',
        ]);

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'website' => 'nullable|url|max:255',
                'urls' => 'nullable|array|max:20',
                'urls.*.label' => 'required_with:urls|string|max:255',
                'urls.*.url' => 'required_with:urls|url|max:500',
                'urls.*.type' => 'required_with:urls|string|in:website,ecommerce,landing_page,blog,catalog,linktree,other',
                'segment' => 'nullable|string|max:255',
                'target_audience' => 'nullable|string|max:1000',
                'tone_of_voice' => 'nullable|string|max:255',
                'primary_color' => ['nullable', 'string', 'max:7'],
                'secondary_color' => ['nullable', 'string', 'max:7'],
                'accent_color' => ['nullable', 'string', 'max:7'],
                'keywords' => 'nullable|array',
                'keywords.*' => 'string|max:100',
            ]);

            Log::info('BrandsController@store: Validação OK', ['validated_keys' => array_keys($validated)]);

            // Gerar slug único
            $baseSlug = Str::slug($validated['name']);
            $slug = $baseSlug;
            $counter = 1;
            while (Brand::withTrashed()->where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }
            $validated['slug'] = $slug;

            // Limpar URLs vazias que o frontend pode enviar
            if (isset($validated['urls']) && is_array($validated['urls'])) {
                $validated['urls'] = array_values(array_filter($validated['urls'], function ($url) {
                    return !empty($url['label']) && !empty($url['url']);
                }));
                if (empty($validated['urls'])) {
                    $validated['urls'] = null;
                }
            }

            Log::info('BrandsController@store: Criando marca no banco', ['slug' => $slug, 'name' => $validated['name']]);

            $brand = Brand::create($validated);

            Log::info('BrandsController@store: Marca criada', ['brand_id' => $brand->id]);

            // Vincular TODOS os usuarios a nova marca (sistema unico, sem permissoes)
            $allUsers = \App\Models\User::pluck('id');
            $syncData = [];
            foreach ($allUsers as $userId) {
                $syncData[$userId] = ['role' => $userId === $request->user()->id ? BrandRole::Owner->value : 'admin'];
            }
            $brand->users()->sync($syncData);

            Log::info('BrandsController@store: Usuários vinculados', ['users_count' => count($syncData)]);

            // Definir como marca ativa para quem criou
            $request->user()->switchBrand($brand);

            Log::info('BrandsController@store: Marca ativa definida. Sucesso!');

            return redirect()->route('brands.edit', $brand)
                ->with('success', 'Marca criada com sucesso! Agora adicione logotipos e imagens de referência.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('BrandsController@store: Erro de validação', [
                'errors' => $e->errors(),
                'input' => $request->except(['_token']),
            ]);
            throw $e;

        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('BrandsController@store: Erro de banco de dados', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);
            return redirect()->back()
                ->withInput()
                ->withErrors(['name' => 'Erro ao salvar no banco de dados: ' . $e->getMessage()]);

        } catch (\Exception $e) {
            Log::error('BrandsController@store: Erro inesperado', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return redirect()->back()
                ->withInput()
                ->withErrors(['name' => 'Erro inesperado: ' . $e->getMessage()]);
        }
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
            'urls' => 'nullable|array|max:20',
            'urls.*.label' => 'required|string|max:255',
            'urls.*.url' => 'required|url|max:500',
            'urls.*.type' => 'required|string|in:website,ecommerce,landing_page,blog,catalog,linktree,other',
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

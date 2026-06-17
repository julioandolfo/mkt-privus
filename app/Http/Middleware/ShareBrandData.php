<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para compartilhar dados da marca ativa com todas as páginas Inertia
 */
class ShareBrandData
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $user = $request->user();
            $currentBrand = $user->getActiveBrand();

            // Mantém a sessão sincronizada com a marca ativa. Vários controllers
            // (Email, SMS, Blog, Links) leem session('current_brand_id') para
            // filtrar e criar dados; sem isto a sessão fica nula e os registros
            // nascem sem marca (brand_id NULL) e sem isolamento.
            session(['current_brand_id' => $currentBrand?->id]);

            $brands = $user->brands()->orderBy('name')->get(['brands.id', 'name', 'slug', 'primary_color', 'segment', 'logo_path']);

            Inertia::share([
                'currentBrand' => $currentBrand ? [
                    'id' => $currentBrand->id,
                    'name' => $currentBrand->name,
                    'slug' => $currentBrand->slug,
                    'primary_color' => $currentBrand->primary_color,
                    'secondary_color' => $currentBrand->secondary_color,
                    'segment' => $currentBrand->segment,
                    'logo_path' => $currentBrand->logo_path,
                ] : null,
                'brands' => $brands->map(fn ($brand) => [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'primary_color' => $brand->primary_color,
                    'segment' => $brand->segment,
                    'logo_path' => $brand->logo_path,
                ]),
            ]);
        }

        return $next($request);
    }
}

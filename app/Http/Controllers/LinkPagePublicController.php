<?php

namespace App\Http\Controllers;

use App\Models\LinkPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LinkPagePublicController extends Controller
{
    /**
     * Exibir página pública de links (bio link)
     */
    public function show(string $slug)
    {
        $page = LinkPage::where('slug', $slug)->active()->firstOrFail();

        // Registrar view
        $page->incrementViews();

        return view('links.public', [
            'page' => $page,
            'theme' => $page->getThemeDefaults(),
            'blocks' => $page->active_blocks,
        ]);
    }

    /**
     * Registrar click em bloco (AJAX - chamado pelo frontend público)
     */
    public function click(Request $request, string $slug): JsonResponse
    {
        $request->validate([
            'block_index' => 'required|integer|min:0',
            'url' => 'nullable|string|max:2000',
        ]);

        $page = LinkPage::where('slug', $slug)->active()->first();
        if (!$page) {
            return response()->json(['success' => false], 404);
        }

        $page->recordClick(
            blockIndex: $request->input('block_index'),
            url: $request->input('url'),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            referer: $request->header('referer'),
        );

        return response()->json(['success' => true]);
    }
}

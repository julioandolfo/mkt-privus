<?php

namespace App\Http\Controllers;

use App\Models\EmailAsset;
use App\Models\EmailSavedBlock;
use App\Models\AnalyticsConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class EmailEditorController extends Controller
{
    /**
     * Upload de imagem para o editor
     */
    public function uploadAsset(Request $request)
    {
        $request->validate([
            'file' => 'required|image|max:5120', // 5MB max
            'alt_text' => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        $path = $file->store('email-assets', 'public');

        $dimensions = null;
        try {
            $img = getimagesize($file->getPathname());
            if ($img) {
                $dimensions = ['width' => $img[0], 'height' => $img[1]];
            }
        } catch (\Throwable $e) {}

        $asset = EmailAsset::create([
            'brand_id' => session('current_brand_id'),
            'user_id' => Auth::id(),
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'dimensions' => $dimensions,
            'alt_text' => $request->input('alt_text'),
        ]);

        return response()->json([
            'data' => [
                'id' => $asset->id,
                'src' => $asset->url,
                'name' => $asset->file_name,
                'width' => $dimensions['width'] ?? null,
                'height' => $dimensions['height'] ?? null,
            ],
        ]);
    }

    /**
     * Lista de assets para o editor
     */
    public function listAssets(Request $request)
    {
        $brandId = session('current_brand_id');

        $assets = EmailAsset::where(function ($q) use ($brandId) {
            $q->where('brand_id', $brandId)->orWhereNull('brand_id');
        })
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn($a) => [
                'id' => $a->id,
                'src' => $a->url,
                'name' => $a->file_name,
                'width' => $a->dimensions['width'] ?? null,
                'height' => $a->dimensions['height'] ?? null,
                'size' => $a->formattedFileSize(),
            ]);

        return response()->json(['data' => $assets]);
    }

    /**
     * Remove asset
     */
    public function deleteAsset(EmailAsset $asset)
    {
        Storage::disk('public')->delete($asset->file_path);
        $asset->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Lista de blocos salvos
     */
    public function savedBlocks(Request $request)
    {
        $brandId = session('current_brand_id');
        $category = $request->input('category');

        $blocks = EmailSavedBlock::forBrand($brandId)
            ->when($category, fn($q) => $q->byCategory($category))
            ->latest()
            ->get()
            ->map(fn($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'category' => $b->category,
                'html_content' => $b->html_content,
                'mjml_content' => $b->mjml_content,
                'json_content' => $b->json_content,
                'is_global' => $b->is_global,
            ]);

        return response()->json(['data' => $blocks]);
    }

    /**
     * Salva um bloco reutilizavel
     */
    public function storeSavedBlock(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:header,footer,section,product,custom',
            'html_content' => 'nullable|string',
            'mjml_content' => 'nullable|string',
            'json_content' => 'nullable|array',
            'is_global' => 'boolean',
        ]);

        $block = EmailSavedBlock::create([
            'brand_id' => $request->boolean('is_global') ? null : session('current_brand_id'),
            'user_id' => Auth::id(),
            ...$validated,
        ]);

        return response()->json(['data' => $block, 'message' => 'Bloco salvo!']);
    }

    /**
     * Atualiza um bloco
     */
    public function updateSavedBlock(Request $request, EmailSavedBlock $block)
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'html_content' => 'nullable|string',
            'mjml_content' => 'nullable|string',
            'json_content' => 'nullable|array',
        ]);

        $block->update($validated);
        return response()->json(['data' => $block, 'message' => 'Bloco atualizado!']);
    }

    /**
     * Remove um bloco
     */
    public function destroySavedBlock(EmailSavedBlock $block)
    {
        $block->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Busca produtos WooCommerce para inserir no email
     */
    public function wooProducts(Request $request)
    {
        $brandId = session('current_brand_id');
        $search = $request->input('search', '');

        $connection = AnalyticsConnection::where('platform', 'woocommerce')
            ->where('is_active', true)
            ->where(function ($q) use ($brandId) {
                $q->where('brand_id', $brandId)->orWhereNull('brand_id');
            })
            ->first();

        if (!$connection) {
            return response()->json(['data' => [], 'message' => 'Nenhuma conexão WooCommerce ativa.']);
        }

        $config = $connection->config;
        $storeUrl = rtrim($config['store_url'], '/');

        $params = [
            'per_page' => 20,
            'status' => 'publish',
            'orderby' => 'date',
            'order' => 'desc',
        ];

        if ($search) {
            $params['search'] = $search;
        }

        try {
            $response = Http::withBasicAuth($config['consumer_key'], $config['consumer_secret'])
                ->timeout(15)
                ->get("{$storeUrl}/wp-json/wc/v3/products", $params);

            if (!$response->successful()) {
                return response()->json(['data' => [], 'error' => 'Erro ao buscar produtos.']);
            }

            $products = collect($response->json())->map(fn($p) => [
                'id' => $p['id'],
                'name' => $p['name'],
                'price' => $p['price'],
                'regular_price' => $p['regular_price'],
                'sale_price' => $p['sale_price'],
                'image' => $p['images'][0]['src'] ?? null,
                'permalink' => $p['permalink'],
                'short_description' => strip_tags($p['short_description'] ?? ''),
                'sku' => $p['sku'] ?? '',
            ]);

            return response()->json(['data' => $products]);
        } catch (\Throwable $e) {
            return response()->json(['data' => [], 'error' => $e->getMessage()]);
        }
    }

    /**
     * Gerar conteudo HTML com IA
     */
    public function generateWithAI(Request $request)
    {
        $validated = $request->validate([
            'prompt' => 'required|string|max:2000',
            'type' => 'nullable|in:subject,content,full_template',
            'context' => 'nullable|array',
        ]);

        $type = $validated['type'] ?? 'full_template';
        $brandId = session('current_brand_id');

        // Buscar info da marca
        $brand = \App\Models\Brand::find($brandId);
        $brandContext = $brand ? "Marca: {$brand->name}. Segmento: {$brand->segment}. Tom: {$brand->tone}." : '';

        $systemPrompt = match ($type) {
            'subject' => "Você é um especialista em email marketing. Gere 5 opções de assunto de email atraentes e com alta taxa de abertura. {$brandContext} Responda APENAS com um JSON array: [\"assunto1\", \"assunto2\", ...]",
            'content' => "Você é um copywriter expert em email marketing. Gere o texto do corpo do email em HTML simples (sem <html>, <head>, <body>). Use tags como <h1>, <h2>, <p>, <a>, <strong>. {$brandContext}",
            'full_template' => "Você é um designer e copywriter expert em email marketing. Gere um template completo de email em HTML responsivo e bonito. Use tabelas para layout (compatibilidade com email clients). Inclua: cabeçalho com logo placeholder, conteúdo principal, CTA (call-to-action), e rodapé. Use cores modernas e design clean. {$brandContext} Retorne APENAS o HTML completo (com <html>, <head> com estilos inline, e <body>).",
        };

        // Buscar API key
        $apiKey = \App\Models\Setting::get('api_keys', 'gemini_api_key');
        if (!$apiKey) {
            $apiKey = \App\Models\Setting::get('api_keys', 'openai_api_key');
        }

        if (!$apiKey) {
            return response()->json(['success' => false, 'error' => 'Nenhuma API key de IA configurada.']);
        }

        try {
            // Tentar Gemini primeiro
            $geminiKey = \App\Models\Setting::get('api_keys', 'gemini_api_key');
            if ($geminiKey) {
                $response = Http::timeout(60)->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$geminiKey}",
                    [
                        'contents' => [
                            ['parts' => [['text' => $systemPrompt . "\n\nSolicitação: " . $validated['prompt']]]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.7,
                            'maxOutputTokens' => 4096,
                        ],
                    ]
                );

                if ($response->successful()) {
                    $text = $response->json('candidates.0.content.parts.0.text', '');

                    // Limpar markdown code blocks se houver
                    $text = preg_replace('/^```(?:html|json)?\s*\n?/m', '', $text);
                    $text = preg_replace('/\n?```\s*$/m', '', $text);

                    return response()->json([
                        'success' => true,
                        'content' => trim($text),
                        'type' => $type,
                    ]);
                }
            }

            return response()->json(['success' => false, 'error' => 'Falha ao gerar conteúdo.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}

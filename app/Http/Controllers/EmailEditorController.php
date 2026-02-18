<?php

namespace App\Http\Controllers;

use App\Enums\AIModel;
use App\Models\EmailAsset;
use App\Models\EmailSavedBlock;
use App\Models\AnalyticsConnection;
use App\Models\Brand;
use App\Models\SystemLog;
use App\Services\AI\AIGateway;
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
        $brand = Brand::find($brandId);
        $user = Auth::user();

        $brandInfo = '';
        if ($brand) {
            $brandInfo = "\nMarca: {$brand->name}."
                . ($brand->segment ? " Segmento: {$brand->segment}." : '')
                . ($brand->tone_of_voice ? " Tom de voz: {$brand->tone_of_voice}." : '')
                . ($brand->description ? " Sobre: {$brand->description}." : '');
        }

        $systemPrompt = match ($type) {
            'subject' => "Você é um especialista em email marketing com alta taxa de abertura.{$brandInfo}\nGere 5 opções criativas e atraentes de assunto para o email descrito. Responda APENAS com um JSON array de strings: [\"assunto1\", \"assunto2\", \"assunto3\", \"assunto4\", \"assunto5\"]",
            'content' => "Você é um copywriter expert em email marketing.{$brandInfo}\nGere o texto do corpo do email em HTML (sem <html>, <head>, <body> — apenas o conteúdo interno). Use tags como <h1>, <h2>, <p>, <a href='#'>, <strong>, <ul>, <li>. Estilos inline onde necessário. Responda APENAS com o HTML.",
            'full_template' => "Você é um designer e copywriter expert em email marketing.{$brandInfo}\nGere um template completo de email em HTML responsivo. Regras:\n- Use tabelas para layout (compatibilidade com clientes de email)\n- Toda estilização deve ser inline (style=\"...\")\n- Inclua: header com logo placeholder, conteúdo principal atraente, botão CTA destacado, footer com unsubscribe link\n- Use paleta de cores moderna e profissional\n- Largura máxima de 600px centralizada\n- Fontes seguras para email (Arial, Helvetica, sans-serif)\nResponda APENAS com o HTML completo (iniciando em <!DOCTYPE html>).",
            default => "Você é um especialista em email marketing.{$brandInfo}",
        };

        try {
            $aiGateway = app(AIGateway::class);

            // Determinar modelo disponivel (Gemini preferido, OpenAI como fallback)
            $model = $this->resolveAIModel();
            if (!$model) {
                return response()->json([
                    'success' => false,
                    'error' => 'Nenhuma API key de IA configurada. Configure em Configurações → Integrações.',
                ]);
            }

            $response = $aiGateway->chat(
                model: $model,
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $validated['prompt']],
                ],
                brand: $brand,
                user: $user,
                feature: 'email_ai_generation',
                options: [
                    'temperature' => 0.75,
                    'max_tokens' => $type === 'full_template' ? 6000 : 2000,
                ],
            );

            $text = $response['content'] ?? '';

            // Limpar markdown code blocks se houver
            $text = preg_replace('/^```(?:html|json)?\s*\n?/im', '', $text);
            $text = preg_replace('/\n?```\s*$/m', '', $text);
            $text = trim($text);

            if (empty($text)) {
                SystemLog::warning('email', 'ai.empty_response', "IA retornou conteúdo vazio para geração de email", [
                    'type' => $type,
                    'model' => $model->value,
                    'brand_id' => $brandId,
                ]);
                return response()->json(['success' => false, 'error' => 'A IA não retornou conteúdo. Tente novamente ou ajuste o prompt.']);
            }

            SystemLog::info('email', 'ai.generated', "Conteúdo de email gerado por IA: tipo={$type}", [
                'type' => $type,
                'model' => $model->value,
                'brand_id' => $brandId,
                'tokens' => ($response['input_tokens'] ?? 0) + ($response['output_tokens'] ?? 0),
            ]);

            return response()->json([
                'success' => true,
                'content' => $text,
                'type' => $type,
                'model' => $model->value,
            ]);
        } catch (\Throwable $e) {
            SystemLog::error('email', 'ai.generation_error', "Erro ao gerar conteúdo de email: {$e->getMessage()}", [
                'type' => $type,
                'brand_id' => $brandId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erro ao comunicar com a IA: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve o modelo de IA disponivel baseado nas API keys configuradas
     */
    private function resolveAIModel(): ?AIModel
    {
        $geminiKey = \App\Models\Setting::get('api_keys', 'gemini_api_key');
        if ($geminiKey) {
            return AIModel::GeminiFlash;
        }

        $openaiKey = \App\Models\Setting::get('api_keys', 'openai_api_key');
        if ($openaiKey) {
            return AIModel::GPT4oMini;
        }

        return null;
    }
}

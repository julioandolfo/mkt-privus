<?php

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\PlatformType;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Social\BrandDNAAnalyzerService;
use App\Services\Social\CampaignIdeasGeneratorService;
use App\Services\Social\NaturalLanguageEditorService;
use App\Services\AI\AIGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller do Content Engine V2 - Inspirado no Pomeli
 * Funcionalidades: Brand DNA Analysis, Campaign Generator, Natural Language Editor
 */
class ContentEngineV2Controller extends Controller
{
    public function __construct(
        private BrandDNAAnalyzerService $brandAnalyzer,
        private CampaignIdeasGeneratorService $campaignGenerator,
        private NaturalLanguageEditorService $editor,
        private AIGateway $aiGateway
    ) {}

    /**
     * Página principal do Content Engine V2
     */
    public function index(Request $request): Response
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return Inertia::render('Social/ContentEngineV2/Index', [
                'brandDna' => null,
                'hasAnalysis' => false,
                'campaigns' => [],
                'presetCommands' => [],
            ]);
        }

        // Verifica se tem análise completa
        $hasAnalysis = $this->brandAnalyzer->hasCompleteAnalysis($brand);
        $brandDna = $this->brandAnalyzer->getCachedAnalysis($brand);

        // Obtém campanhas recentes
        $recentCampaigns = $this->campaignGenerator->getRecentIdeas($brand);

        return Inertia::render('Social/ContentEngineV2/Index', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'segment' => $brand->segment,
                'website' => $brand->website,
            ],
            'brandDna' => $brandDna,
            'hasAnalysis' => $hasAnalysis,
            'recentCampaigns' => $recentCampaigns,
            'presetCommands' => $this->editor->getPresetCommands(),
        ]);
    }

    /**
     * Analisa/Reanalisa o Brand DNA
     */
    public function analyzeBrand(Request $request): RedirectResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return redirect()->back()->withErrors(['brand' => 'Selecione uma marca.']);
        }

        try {
            $dna = $this->brandAnalyzer->reanalyzeBrand($brand);

            return redirect()->back()->with('success', 'Análise de marca concluída! Brand DNA atualizado.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['analysis' => 'Erro na análise: ' . $e->getMessage()]);
        }
    }

    /**
     * Retorna Brand DNA atual
     */
    public function getBrandDna(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json(['error' => 'Selecione uma marca'], 400);
        }

        $dna = $this->brandAnalyzer->getCachedAnalysis($brand);

        if (!$dna) {
            return response()->json([
                'has_analysis' => false,
                'message' => 'Brand DNA ainda não foi analisado',
            ]);
        }

        return response()->json([
            'has_analysis' => true,
            'brand_dna' => $dna,
            'analyzed_at' => $dna['_meta']['analyzed_at'] ?? null,
        ]);
    }

    /**
     * Gera ideias de campanha
     */
    public function generateCampaigns(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json(['error' => 'Selecione uma marca'], 400);
        }

        $validated = $request->validate([
            'theme' => 'nullable|string|max:255',
            'count' => 'integer|min:3|max:10',
            'force_regenerate' => 'boolean',
        ]);

        try {
            // Força reanálise se solicitado
            if ($validated['force_regenerate'] ?? false) {
                $this->brandAnalyzer->reanalyzeBrand($brand);
            }

            $campaigns = $this->campaignGenerator->generateCampaignIdeas(
                $brand,
                $validated['theme'] ?? null,
                $validated['count'] ?? 5
            );

            // Salva como recentes
            $this->campaignGenerator->saveRecentIdeas($brand, $campaigns);

            return response()->json([
                'success' => true,
                'campaigns' => $campaigns,
                'count' => count($campaigns),
                'theme' => $validated['theme'] ?? null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erro ao gerar campanhas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Gera campanha a partir de ideia do usuário
     */
    public function generateFromUserIdea(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json(['error' => 'Selecione uma marca'], 400);
        }

        $validated = $request->validate([
            'idea' => 'required|string|min:10|max:500',
            'post_count' => 'integer|min:1|max:5',
        ]);

        try {
            $campaign = $this->campaignGenerator->generateFromUserIdea(
                $brand,
                $validated['idea'],
                $validated['post_count'] ?? 3
            );

            return response()->json([
                'success' => true,
                'campaign' => $campaign,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Regenera variação de campanha
     */
    public function regenerateCampaignVariant(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json(['error' => 'Selecione uma marca'], 400);
        }

        $validated = $request->validate([
            'campaign' => 'required|array',
            'variation_type' => 'required|string|in:different_angle,different_format,expanded,condensed,humorous,serious',
        ]);

        try {
            $variant = $this->campaignGenerator->regenerateCampaignVariant(
                $brand,
                $validated['campaign'],
                $validated['variation_type']
            );

            return response()->json([
                'success' => true,
                'variant' => $variant,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cria posts a partir de uma campanha
     */
    public function createPostsFromCampaign(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json(['error' => 'Selecione uma marca'], 400);
        }

        $validated = $request->validate([
            'campaign' => 'required|array',
            'selected_posts' => 'nullable|array',
            'generate_images' => 'boolean',
        ]);

        $campaign = $validated['campaign'];
        $selectedPosts = $validated['selected_posts'] ?? array_keys($campaign['posts'] ?? []);
        $generateImages = $validated['generate_images'] ?? true;

        $createdPosts = [];

        try {
            foreach ($selectedPosts as $postIndex) {
                if (!isset($campaign['posts'][$postIndex])) {
                    continue;
                }

                $postData = $campaign['posts'][$postIndex];

                // Gera legenda completa
                $caption = $this->generateFullCaption($brand, $campaign, $postData);

                // Cria o Post
                $post = Post::create([
                    'brand_id' => $brand->id,
                    'user_id' => $request->user()->id,
                    'title' => $postData['hook'] ?? $campaign['name'],
                    'caption' => $caption,
                    'hashtags' => $this->generateHashtags($brand, $campaign),
                    'platforms' => $campaign['platforms'] ?? ['instagram'],
                    'type' => $campaign['format'] ?? 'feed',
                    'status' => PostStatus::Draft,
                    'scheduled_at' => $this->suggestScheduleTime($campaign['platforms'] ?? ['instagram']),
                ]);

                // Gera imagem se solicitado
                if ($generateImages) {
                    $this->generatePostImage($post, $brand, $campaign, $postData);
                }

                $createdPosts[] = [
                    'id' => $post->id,
                    'title' => $post->title,
                    'platforms' => $post->platforms,
                ];
            }

            return response()->json([
                'success' => true,
                'created_posts' => $createdPosts,
                'count' => count($createdPosts),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Edita conteúdo via linguagem natural
     */
    public function naturalEdit(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json(['error' => 'Selecione uma marca'], 400);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:5000',
            'command' => 'required|string|max:500',
            'platform' => 'nullable|string|in:instagram,facebook,linkedin,tiktok,youtube,pinterest,twitter',
        ]);

        try {
            $result = $this->editor->editContent(
                $validated['content'],
                $validated['command'],
                $brand,
                $validated['platform'] ?? null
            );

            return response()->json([
                'success' => true,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Aplica comando pré-definido
     */
    public function applyPreset(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json(['error' => 'Selecione uma marca'], 400);
        }

        $validated = $request->validate([
            'content' => 'required|string',
            'preset_key' => 'required|string',
        ]);

        try {
            $result = $this->editor->applyPreset(
                $validated['content'],
                $validated['preset_key'],
                $brand
            );

            return response()->json([
                'success' => true,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sugere melhorias para conteúdo
     */
    public function suggestImprovements(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json(['error' => 'Selecione uma marca'], 400);
        }

        $validated = $request->validate([
            'content' => 'required|string',
            'platform' => 'required|string|in:instagram,facebook,linkedin,tiktok,youtube,pinterest,twitter',
        ]);

        try {
            $suggestions = $this->editor->suggestImprovements(
                $validated['content'],
                $validated['platform'],
                $brand
            );

            return response()->json([
                'success' => true,
                'suggestions' => $suggestions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Gera variações do conteúdo
     */
    public function generateVariations(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json(['error' => 'Selecione uma marca'], 400);
        }

        $validated = $request->validate([
            'content' => 'required|string',
            'count' => 'integer|min:2|max:5',
        ]);

        try {
            $variations = $this->editor->generateVariations(
                $validated['content'],
                $validated['count'] ?? 3,
                $brand
            );

            return response()->json([
                'success' => true,
                'variations' => $variations,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retorna lista de comandos pré-definidos
     */
    public function getPresetCommands(): JsonResponse
    {
        return response()->json([
            'commands' => $this->editor->getPresetCommands(),
        ]);
    }

    // ===== PRIVATE METHODS =====

    /**
     * Gera legenda completa para um post
     */
    private function generateFullCaption(Brand $brand, array $campaign, array $postData): string
    {
        $hook = $postData['hook'] ?? '';
        $angle = $postData['angle'] ?? '';
        $cta = $postData['cta'] ?? '';

        // Monta estrutura básica
        $caption = $hook . "\n\n";
        $caption .= "✨ " . $angle . "\n\n";

        // Adiciona contexto do conceito da campanha
        if (!empty($campaign['concept'])) {
            $caption .= $campaign['concept'] . "\n\n";
        }

        $caption .= $cta;

        return $caption;
    }

    /**
     * Gera hashtags para a campanha
     */
    private function generateHashtags(Brand $brand, array $campaign): array
    {
        $brandName = strtolower(str_replace(' ', '', $brand->name));
        $segment = strtolower($brand->segment ?? 'negocio');

        $baseHashtags = [
            $brandName,
            $segment,
            'marketingdigital',
            'conteudo',
        ];

        // Adiciona hashtags baseadas no conceito
        $conceptWords = explode(' ', strtolower($campaign['concept'] ?? ''));
        $conceptHashtags = array_slice(array_filter($conceptWords, fn($w) => strlen($w) > 4), 0, 3);

        return array_slice(array_unique(array_merge($baseHashtags, $conceptHashtags)), 0, 8);
    }

    /**
     * Sugere horário de agendamento
     */
    private function suggestScheduleTime(array $platforms): ?\DateTime
    {
        $bestTimes = [
            'instagram' => '18:00',
            'facebook' => '15:00',
            'linkedin' => '12:00',
            'tiktok' => '19:00',
            'youtube' => '14:00',
            'pinterest' => '20:00',
            'twitter' => '09:00',
        ];

        $platform = $platforms[0] ?? 'instagram';
        $time = $bestTimes[$platform] ?? '18:00';

        // Agenda para amanhã no horário sugerido
        return now()->addDay()->setTimeFromTimeString($time);
    }

    /**
     * Gera imagem para o post
     */
    private function generatePostImage(Post $post, Brand $brand, array $campaign, array $postData): void
    {
        try {
            $visualDescription = $postData['visual_description'] ?? '';
            $topic = $campaign['name'] ?? $post->title;

            $imageResult = $this->aiGateway->tryGenerateImageForContent(
                brand: $brand,
                topic: $topic,
                caption: $post->caption,
                platform: $post->platforms[0] ?? 'instagram',
                postType: $post->type
            );

            if ($imageResult && !empty($imageResult['path'])) {
                $fileSize = Storage::disk('public')->size($imageResult['path']);
                $dimensions = explode('x', $imageResult['size'] ?? '1024x1024');

                PostMedia::create([
                    'post_id' => $post->id,
                    'type' => 'image',
                    'file_path' => $imageResult['path'],
                    'file_name' => basename($imageResult['path']),
                    'mime_type' => 'image/png',
                    'file_size' => $fileSize,
                    'width' => (int) ($dimensions[0] ?? 1024),
                    'height' => (int) ($dimensions[1] ?? 1024),
                    'order' => 0,
                    'alt_text' => $post->title,
                    'metadata' => [
                        'source' => 'ai_generated_v2',
                        'model' => $imageResult['model'] ?? 'dall-e-3',
                        'prompt' => $imageResult['prompt'] ?? null,
                        'campaign_id' => $campaign['id'] ?? null,
                    ],
                ]);
            }
        } catch (\Exception $e) {
            // Silencia erro de geração de imagem
            \Log::warning("Failed to generate image for post {$post->id}: {$e->getMessage()}");
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\EmailAsset;
use App\Models\EmailCampaign;
use App\Models\SystemLog;
use App\Services\Email\SvgConverterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Comando para converter imagens SVG existentes para PNG
 * Necessário porque SVG não é suportado pela maioria dos clientes de email
 */
class FixSvgImagesCommand extends Command
{
    protected $signature = 'email:fix-svg-images
                            {--campaign= : ID da campanha específica}
                            {--dry-run : Apenas mostrar o que seria feito, sem converter}
                            {--force : Converter mesmo sem verificação}';

    protected $description = 'Converte imagens SVG para PNG em campanhas e assets existentes';

    private SvgConverterService $svgConverter;

    public function __construct()
    {
        parent::__construct();
        $this->svgConverter = new SvgConverterService();
    }

    public function handle(): int
    {
        $this->info("=== Conversão de SVG para PNG ===\n");

        // Verificar se conversão está disponível
        if (!$this->svgConverter->isConversionAvailable()) {
            $this->error("❌ Nenhum conversor SVG disponível!");
            $this->warn("Instale uma das opções:");
            $this->warn("  - PHP Imagick extension com suporte SVG");
            $this->warn("  - ImageMagick CLI (magick/convert)");
            $this->warn("  - Inkscape CLI");
            $this->newLine();
            $this->warn("Ou use a opção --force para processar assim mesmo.");
            return 1;
        }

        $method = $this->svgConverter->getAvailableMethod();
        $this->info("✅ Conversor disponível: {$method}");
        $this->newLine();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn("📝 MODO SIMULAÇÃO: Nenhuma alteração será feita\n");
        }

        // Se especificou uma campanha, processa apenas ela
        $campaignId = $this->option('campaign');

        if ($campaignId) {
            return $this->processCampaign($campaignId, $dryRun);
        }

        // Processa todas as campanhas
        return $this->processAllCampaigns($dryRun);
    }

    private function processCampaign(int $campaignId, bool $dryRun): int
    {
        $campaign = EmailCampaign::find($campaignId);

        if (!$campaign) {
            $this->error("Campanha {$campaignId} não encontrada");
            return 1;
        }

        $this->info("Campanha: {$campaign->name}");
        $this->info("Status: {$campaign->status}");
        $this->newLine();

        // Encontra SVGs no HTML
        $svgImages = $this->findSvgImages($campaign->html_content);

        if (empty($svgImages)) {
            $this->info("✅ Nenhuma imagem SVG encontrada nesta campanha");
            return 0;
        }

        $this->warn("⚠️  Encontradas " . count($svgImages) . " imagem(s) SVG:");
        foreach ($svgImages as $img) {
            $this->line("  - {$img['src']}");
        }
        $this->newLine();

        if ($dryRun) {
            $this->info("Modo simulação - nenhuma ação realizada");
            return 0;
        }

        // Pergunta confirmação
        if (!$this->confirm("Deseja converter estas imagens para PNG?", true)) {
            $this->info("Operação cancelada");
            return 0;
        }

        // Processa cada SVG
        $converted = 0;
        $failed = 0;

        foreach ($svgImages as $img) {
            $result = $this->convertSvgInCampaign($campaign, $img['src']);
            if ($result) {
                $converted++;
                $this->info("✅ Convertido: {$result}");
            } else {
                $failed++;
                $this->error("❌ Falha: {$img['src']}");
            }
        }

        $this->newLine();
        $this->info("Resumo: {$converted} convertidos, {$failed} falhas");

        return $failed > 0 ? 1 : 0;
    }

    private function processAllCampaigns(bool $dryRun): int
    {
        // Encontra todas as campanhas com SVG
        $campaigns = EmailCampaign::where('html_content', 'like', '%.svg%')->get();

        if ($campaigns->isEmpty()) {
            $this->info("✅ Nenhuma campanha com SVG encontrada");
            return 0;
        }

        $this->info("Encontradas {$campaigns->count()} campanha(s) com possível SVG");
        $this->newLine();

        $totalSvgs = 0;
        $campaignsWithSvg = [];

        foreach ($campaigns as $campaign) {
            $svgs = $this->findSvgImages($campaign->html_content);
            if (!empty($svgs)) {
                $campaignsWithSvg[] = [
                    'campaign' => $campaign,
                    'svgs' => $svgs,
                ];
                $totalSvgs += count($svgs);
            }
        }

        if (empty($campaignsWithSvg)) {
            $this->info("✅ Nenhuma imagem SVG real encontrada nas campanhas");
            return 0;
        }

        $this->warn("⚠️  Encontradas {$totalSvgs} imagem(s) SVG em " . count($campaignsWithSvg) . " campanha(s):");
        $this->newLine();

        foreach ($campaignsWithSvg as $item) {
            $this->line("Campanha: {$item['campaign']->name} (ID: {$item['campaign']->id})");
            foreach ($item['svgs'] as $svg) {
                $this->line("  - {$svg['src']}");
            }
            $this->newLine();
        }

        if ($dryRun) {
            return 0;
        }

        if (!$this->confirm("Deseja converter todas estas imagens?", true)) {
            $this->info("Operação cancelada");
            return 0;
        }

        // Processa todas
        $totalConverted = 0;
        $totalFailed = 0;

        foreach ($campaignsWithSvg as $item) {
            $this->info("Processando: {$item['campaign']->name}");

            foreach ($item['svgs'] as $svg) {
                $result = $this->convertSvgInCampaign($item['campaign'], $svg['src']);
                if ($result) {
                    $totalConverted++;
                } else {
                    $totalFailed++;
                }
            }
        }

        $this->newLine();
        $this->info("=== RESUMO FINAL ===");
        $this->info("Convertidos: {$totalConverted}");
        $this->info("Falhas: {$totalFailed}");

        return $totalFailed > 0 ? 1 : 0;
    }

    /**
     * Encontra todas as imagens SVG no HTML
     */
    private function findSvgImages(?string $html): array
    {
        if (empty($html)) {
            return [];
        }

        $svgs = [];
        $pattern = '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i';

        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $src = $match[1];

            // Verifica se é SVG pela extensão
            if (str_ends_with(strtolower($src), '.svg') ||
                str_contains(strtolower($src), '.svg?')) {
                $svgs[] = [
                    'src' => $src,
                    'tag' => $match[0],
                ];
            }
        }

        return $svgs;
    }

    /**
     * Converte um SVG específico em uma campanha
     */
    private function convertSvgInCampaign(EmailCampaign $campaign, string $svgUrl): ?string
    {
        try {
            // Verifica se é um asset nosso
            if (str_contains($svgUrl, '/storage/email-assets/')) {
                // Extrair o caminho do arquivo
                $path = str_replace(Storage::disk('public')->url(''), '', $svgUrl);
                $path = ltrim($path, '/');

                if (!Storage::disk('public')->exists($path)) {
                    $this->warn("  Arquivo não encontrado: {$path}");
                    return null;
                }

                // Converter
                $pngPath = $this->svgConverter->convertToPng($path);

                if (!$pngPath) {
                    return null;
                }

                // Atualizar HTML da campanha
                $newUrl = Storage::disk('public')->url($pngPath);
                $html = str_replace($svgUrl, $newUrl, $campaign->html_content);
                $campaign->update(['html_content' => $html]);

                // Atualizar asset no banco
                $asset = EmailAsset::where('file_path', $path)->first();
                if ($asset) {
                    $fullPngPath = Storage::disk('public')->path($pngPath);
                    $imageContent = file_get_contents($fullPngPath);
                    $img = getimagesizefromstring($imageContent);

                    $asset->update([
                        'file_path' => $pngPath,
                        'mime_type' => 'image/png',
                    ]);
                }

                SystemLog::info('email', 'svg.campaign_converted', "SVG convertido em campanha", [
                    'campaign_id' => $campaign->id,
                    'svg_url' => $svgUrl,
                    'png_url' => $newUrl,
                ]);

                return $newUrl;
            }

            // URL externa - tenta baixar e converter
            $this->warn("  URL externa não suportada para conversão automática: {$svgUrl}");
            return null;

        } catch (\Throwable $e) {
            SystemLog::error('email', 'svg.convert_error', "Erro ao converter SVG em campanha: {$e->getMessage()}", [
                'campaign_id' => $campaign->id,
                'svg_url' => $svgUrl,
            ]);
            return null;
        }
    }
}

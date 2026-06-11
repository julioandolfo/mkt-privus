<?php
/**
 * Script para converter SVGs já existentes em uma campanha específica
 * Uso: php convert_existing_svg.php CAMPAIGN_ID
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\EmailCampaign;
use App\Models\EmailAsset;
use App\Models\SystemLog;
use App\Services\Email\SvgConverterService;
use Illuminate\Support\Facades\Storage;

$campaignId = $argv[1] ?? null;

if (!$campaignId) {
    echo "Uso: php convert_existing_svg.php CAMPAIGN_ID\n";
    exit(1);
}

$campaign = EmailCampaign::find($campaignId);

if (!$campaign) {
    echo "Campanha {$campaignId} não encontrada\n";
    exit(1);
}

echo "===========================================\n";
echo "Campanha: {$campaign->name}\n";
echo "ID: {$campaign->id}\n";
echo "===========================================\n\n";

// Encontra todas as imagens no HTML
$html = $campaign->html_content;
$pattern = '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i';
preg_match_all($pattern, $html, $matches);

$svgImages = [];
foreach ($matches[1] as $src) {
    if (str_ends_with(strtolower($src), '.svg') ||
        str_contains(strtolower($src), '.svg?')) {
        $svgImages[] = $src;
    }
}

if (empty($svgImages)) {
    echo "✅ Nenhuma imagem SVG encontrada no HTML\n";
    exit(0);
}

echo "Encontradas " . count($svgImages) . " imagem(s) SVG:\n";
foreach ($svgImages as $svg) {
    echo "  - {$svg}\n";
}
echo "\n";

$converter = new SvgConverterService();
$converted = 0;
$failed = 0;

foreach ($svgImages as $svgUrl) {
    echo "Processando: {$svgUrl}\n";

    // Verifica se é URL do nosso domínio
    $appUrl = config('app.url');
    if (!str_starts_with($svgUrl, $appUrl)) {
        echo "  ⚠️ URL externa, pulando...\n";
        continue;
    }

    // Extrai o caminho relativo
    $relativePath = str_replace($appUrl . '/storage/', '', $svgUrl);
    $relativePath = ltrim($relativePath, '/');

    echo "  Caminho: {$relativePath}\n";

    // Verifica se existe PNG
    $pngPath = str_replace('.svg', '.png', $relativePath);
    if (Storage::disk('public')->exists($pngPath)) {
        echo "  ✅ PNG já existe: {$pngPath}\n";

        // Atualiza HTML
        $newUrl = Storage::disk('public')->url($pngPath);
        $html = str_replace($svgUrl, $newUrl, $html);

        // Atualiza asset no banco
        $asset = EmailAsset::where('file_path', $relativePath)->first();
        if ($asset) {
            $fullPngPath = Storage::disk('public')->path($pngPath);
            $imageContent = file_get_contents($fullPngPath);
            $img = getimagesizefromstring($imageContent);

            $asset->update([
                'file_path' => $pngPath,
                'mime_type' => 'image/png',
                'file_size' => strlen($imageContent),
                'dimensions' => $img ? ['width' => $img[0], 'height' => $img[1]] : null,
            ]);
            echo "  ✅ Asset atualizado no banco\n";
        }

        $converted++;
        continue;
    }

    // Tenta converter
    echo "  🔄 Tentando converter...\n";
    $result = $converter->convertToPng($relativePath);

    if ($result) {
        echo "  ✅ Convertido: {$result}\n";

        // Atualiza HTML
        $newUrl = Storage::disk('public')->url($result);
        $html = str_replace($svgUrl, $newUrl, $html);

        // Atualiza asset no banco
        $asset = EmailAsset::where('file_path', $relativePath)->first();
        if ($asset) {
            $fullPngPath = Storage::disk('public')->path($result);
            $imageContent = file_get_contents($fullPngPath);
            $img = getimagesizefromstring($imageContent);

            $asset->update([
                'file_path' => $result,
                'mime_type' => 'image/png',
                'file_size' => strlen($imageContent),
                'dimensions' => $img ? ['width' => $img[0], 'height' => $img[1]] : null,
            ]);
            echo "  ✅ Asset atualizado no banco\n";
        }

        $converted++;
    } else {
        echo "  ❌ Falha na conversão\n";
        $failed++;
    }
}

// Salva o HTML atualizado
if ($converted > 0) {
    echo "\n💾 Salvando HTML atualizado...\n";
    $campaign->update(['html_content' => $html]);
    echo "✅ Campanha atualizada!\n";
}

echo "\n===========================================\n";
echo "RESUMO\n";
echo "===========================================\n";
echo "Convertidas: {$converted}\n";
echo "Falhas: {$failed}\n";
echo "\n";

if ($converted > 0) {
    echo "✅ Agora você pode recarregar a página da campanha\n";
    echo "   O alerta de SVG deve ter desaparecido!\n";
}

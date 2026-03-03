<?php
/**
 * Força a conversão do SVG atual para PNG e atualiza campanhas
 * Uso: php force_convert_svg.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\EmailAsset;
use App\Models\EmailCampaign;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Storage;

$svgName = 'email_kDhdIkjC1YERSANH_1772484319';

echo "===========================================\n";
echo "FORÇAR CONVERSÃO SVG PARA PNG\n";
echo "===========================================\n\n";

// 1. Verificar métodos disponíveis
echo "1. Verificando conversores disponíveis...\n";

$rsvg = trim(shell_exec('which rsvg-convert 2>/dev/null') ?? '');
$magick = trim(shell_exec('which magick 2>/dev/null') ?? '');
$convert = trim(shell_exec('which convert 2>/dev/null') ?? '');
$inkscape = trim(shell_exec('which inkscape 2>/dev/null') ?? '');
$imagick = extension_loaded('imagick');

echo "   rsvg-convert: " . ($rsvg ? "✅ {$rsvg}" : "❌ não encontrado") . "\n";
echo "   magick CLI:   " . ($magick ? "✅ {$magick}" : "❌ não encontrado") . "\n";
echo "   convert CLI:  " . ($convert ? "✅ {$convert}" : "❌ não encontrado") . "\n";
echo "   inkscape:     " . ($inkscape ? "✅ {$inkscape}" : "❌ não encontrado") . "\n";
echo "   PHP Imagick:  " . ($imagick ? "✅ carregado" : "❌ não carregado") . "\n\n";

// 2. Encontrar o SVG no banco
echo "2. Buscando SVG no banco...\n";
$asset = EmailAsset::where('file_path', 'like', "%{$svgName}%")->first();

if (!$asset) {
    echo "   ❌ Asset não encontrado no banco!\n";

    // Busca diretamente no storage
    $files = glob(Storage::disk('public')->path("email-assets/1/2026/03/{$svgName}*"));
    if ($files) {
        echo "   Arquivo encontrado no storage:\n";
        foreach ($files as $f) echo "     - {$f}\n";
        // Usa o caminho direto
        $svgFullPath = $files[0];
        $svgRelativePath = str_replace(Storage::disk('public')->path('') . DIRECTORY_SEPARATOR, '', $svgFullPath);
        $svgRelativePath = str_replace('\\', '/', $svgRelativePath);
    } else {
        echo "   ❌ Arquivo também não encontrado no storage!\n";
        exit(1);
    }
} else {
    echo "   ✅ Asset ID: {$asset->id}\n";
    echo "   Caminho: {$asset->file_path}\n";
    echo "   MIME: {$asset->mime_type}\n\n";
    $svgRelativePath = $asset->file_path;
}

$svgFullPath = Storage::disk('public')->path($svgRelativePath);
$pngRelativePath = preg_replace('/\.svg$/i', '.png', $svgRelativePath);
$pngFullPath = Storage::disk('public')->path($pngRelativePath);

echo "3. Convertendo SVG para PNG...\n";
echo "   SVG: {$svgFullPath}\n";
echo "   PNG destino: {$pngFullPath}\n\n";

// Se PNG já existe, remove para forçar reconversão
if (file_exists($pngFullPath)) {
    echo "   ⚠️ PNG já existia, removendo para reconverter...\n";
    unlink($pngFullPath);
}

$converted = false;

// Tenta rsvg-convert primeiro (melhor para SVG)
if ($rsvg) {
    echo "   Tentando rsvg-convert...\n";
    $cmd = "rsvg-convert -o \"{$pngFullPath}\" \"{$svgFullPath}\" 2>&1";
    $output = shell_exec($cmd);
    echo "   Output: " . ($output ?: '(sem output)') . "\n";
    if (file_exists($pngFullPath) && filesize($pngFullPath) > 100) {
        echo "   ✅ rsvg-convert: OK! PNG gerado (" . filesize($pngFullPath) . " bytes)\n";
        $converted = true;
    } else {
        echo "   ❌ rsvg-convert: falhou\n";
    }
}

// Tenta ImageMagick CLI
if (!$converted && ($magick || $convert)) {
    $cmd_bin = $magick ?: $convert;
    // Verifica se é o ImageMagick real
    $version = shell_exec("{$cmd_bin} --version 2>&1");
    if ($version && str_contains($version, 'ImageMagick')) {
        echo "   Tentando ImageMagick ({$cmd_bin})...\n";
        $cmd = "{$cmd_bin} \"{$svgFullPath}\" \"{$pngFullPath}\" 2>&1";
        $output = shell_exec($cmd);
        echo "   Output: " . ($output ?: '(sem output)') . "\n";
        if (file_exists($pngFullPath) && filesize($pngFullPath) > 100) {
            echo "   ✅ ImageMagick: OK! PNG gerado (" . filesize($pngFullPath) . " bytes)\n";
            $converted = true;
        } else {
            echo "   ❌ ImageMagick: falhou\n";
        }
    }
}

// Tenta PHP Imagick
if (!$converted && $imagick) {
    echo "   Tentando PHP Imagick...\n";
    try {
        $im = new Imagick();
        $im->setBackgroundColor(new ImagickPixel('white'));
        $svgContent = file_get_contents($svgFullPath);
        $im->readImageBlob($svgContent);
        $im->setImageFormat('png32');
        $im->writeImage($pngFullPath);
        $im->clear();
        if (file_exists($pngFullPath) && filesize($pngFullPath) > 100) {
            echo "   ✅ PHP Imagick: OK! PNG gerado (" . filesize($pngFullPath) . " bytes)\n";
            $converted = true;
        } else {
            echo "   ❌ PHP Imagick: falhou\n";
        }
    } catch (Exception $e) {
        echo "   ❌ PHP Imagick: erro - {$e->getMessage()}\n";
    }
}

if (!$converted) {
    echo "\n❌ NENHUM MÉTODO CONSEGUIU CONVERTER!\n";
    echo "\nSolução: Faça o rebuild dos containers com o Dockerfile atualizado:\n";
    echo "  docker-compose down && docker-compose build --no-cache && docker-compose up -d\n";
    exit(1);
}

echo "\n4. Atualizando banco de dados...\n";

$pngContent = file_get_contents($pngFullPath);
$imgSize = getimagesizefromstring($pngContent);

if ($asset) {
    $asset->update([
        'file_path' => $pngRelativePath,
        'mime_type' => 'image/png',
        'file_size' => strlen($pngContent),
        'dimensions' => $imgSize ? ['width' => $imgSize[0], 'height' => $imgSize[1]] : null,
    ]);
    echo "   ✅ Asset #{$asset->id} atualizado no banco\n";
}

// 5. Atualizar campanhas que referenciam o SVG
echo "\n5. Atualizando campanhas...\n";

$pngUrl = Storage::disk('public')->url($pngRelativePath);
$campaigns = EmailCampaign::where('html_content', 'like', "%{$svgName}%")->get();

if ($campaigns->isEmpty()) {
    echo "   ⚠️ Nenhuma campanha referenciando este arquivo\n";
} else {
    foreach ($campaigns as $campaign) {
        // Substitui tanto .svg quanto a URL completa
        $newHtml = preg_replace(
            '/https?:\/\/[^"\']*' . preg_quote($svgName, '/') . '\.svg[^"\']*/',
            $pngUrl,
            $campaign->html_content
        );

        if ($newHtml !== $campaign->html_content) {
            $campaign->update(['html_content' => $newHtml]);
            echo "   ✅ Campanha #{$campaign->id} ({$campaign->name}) atualizada\n";
        } else {
            echo "   ⚠️ Campanha #{$campaign->id}: URL não encontrada no HTML\n";
        }
    }
}

echo "\n===========================================\n";
echo "✅ CONCLUÍDO!\n";
echo "PNG gerado: " . Storage::disk('public')->url($pngRelativePath) . "\n";
echo "\nRecarregue a página da campanha - o alerta SVG deve sumir!\n";
echo "===========================================\n";

<?php
/**
 * Script para verificar se a conversão SVG está funcionando
 * Rode: php check_conversion.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "===========================================\n";
echo "VERIFICAÇÃO DE CONVERSÃO SVG\n";
echo "===========================================\n\n";

// 1. Verificar Imagick
$imagickLoaded = extension_loaded('imagick');
echo "1. PHP Imagick Extension: " . ($imagickLoaded ? "✅ CARREGADA" : "❌ NÃO CARREGADA") . "\n";

if ($imagickLoaded) {
    $imagick = new Imagick();
    $formats = $imagick->queryFormats();
    $hasSvg = in_array('SVG', $formats) || in_array('MSVG', $formats) || in_array('SVGZ', $formats);
    echo "   Suporte a SVG: " . ($hasSvg ? "✅ SIM" : "⚠️ NÃO") . "\n";
    echo "   Versão Imagick: " . Imagick::getVersion()['versionString'] . "\n";
}

// 2. Verificar ImageMagick CLI
echo "\n2. ImageMagick CLI:\n";
$convertOutput = shell_exec('which convert 2>&1');
$magickOutput = shell_exec('which magick 2>&1');
$hasConvert = !empty($convertOutput) && !str_contains($convertOutput, 'not found');
$hasMagick = !empty($magickOutput) && !str_contains($magickOutput, 'not found');

if ($hasMagick) {
    echo "   ✅ Comando 'magick' encontrado: " . trim($magickOutput) . "\n";
} elseif ($hasConvert) {
    echo "   ✅ Comando 'convert' encontrado: " . trim($convertOutput) . "\n";
} else {
    echo "   ❌ Nenhum comando ImageMagick encontrado\n";
}

// 3. Verificar Inkscape
echo "\n3. Inkscape CLI:\n";
$inkscapeOutput = shell_exec('which inkscape 2>&1');
$hasInkscape = !empty($inkscapeOutput) && !str_contains($inkscapeOutput, 'not found');
echo "   " . ($hasInkscape ? "✅ Encontrado: " . trim($inkscapeOutput) : "❌ Não encontrado") . "\n";

// 4. Resumo do serviço Laravel
echo "\n===========================================\n";
echo "RESUMO DO SERVIÇO LARAVEL\n";
echo "===========================================\n";

$service = new \App\Services\Email\SvgConverterService();
$available = $service->isConversionAvailable();
$method = $service->getAvailableMethod();

echo "Conversão disponível: " . ($available ? "✅ SIM" : "❌ NÃO") . "\n";
if ($available) {
    echo "Método ativo: " . strtoupper($method) . "\n";
}

// 5. Verificar SVGs no banco
echo "\n===========================================\n";
echo "SVGS NO BANCO DE DADOS\n";
echo "===========================================\n";

$svgAssets = \App\Models\EmailAsset::where('mime_type', 'image/svg+xml')
    ->orWhere('file_path', 'like', '%.svg%')
    ->count();

echo "Assets SVG: {$svgAssets}\n";

$svgCampaigns = \App\Models\EmailCampaign::where('html_content', 'like', '%.svg%')->count();
echo "Campanhas com SVG: {$svgCampaigns}\n";

echo "\n===========================================\n";
echo "Para converter SVGs de uma campanha:\n";
echo "php artisan email:fix-svg-images --campaign=ID\n";
echo "===========================================\n";

<?php
/**
 * Diagnóstico: verifica onde o src das imagens desaparece
 * Uso: php diagnose_img.php CAMPAIGN_ID
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\EmailCampaign;

$id = $argv[1] ?? null;
if (!$id) { echo "Uso: php diagnose_img.php CAMPAIGN_ID\n"; exit(1); }

$campaign = EmailCampaign::findOrFail($id);

echo "=== DIAGNÓSTICO DE IMAGENS ===\n";
echo "Campanha: {$campaign->name} (ID: {$id})\n\n";

$html = $campaign->html_content;

// STEP 0: HTML bruto do banco
echo "STEP 0 — HTML bruto do banco:\n";
preg_match_all('/<img[^>]*>/i', $html, $m0);
if (empty($m0[0])) {
    echo "  ⚠️ NENHUMA tag <img> encontrada no HTML!\n";
} else {
    foreach ($m0[0] as $i => $tag) {
        $hasSrc = preg_match('/src=/i', $tag);
        echo "  IMG #{$i}: " . ($hasSrc ? '✅ TEM src' : '❌ SEM src') . "\n";
        echo "    " . substr($tag, 0, 300) . "\n\n";
    }
}

// STEP 1: Após CSS inliner
echo "\nSTEP 1 — Após inlineCssForEmail:\n";
$css = '';
if (preg_match('/<style[^>]*>(.*?)<\/style>/si', $html, $cssMatch)) {
    $css = $cssMatch[1];
}

// Testar com placeholders de comentário (minha correção)
$imgMap = [];
$htmlProtected = preg_replace_callback('/<img[^>]*\/?>/i', function ($m) use (&$imgMap) {
    $key = '<!--IMGHOLD' . count($imgMap) . '-->';
    $imgMap[$key] = $m[0];
    return $key;
}, $html);

echo "  Tags <img> removidas e substituídas por " . count($imgMap) . " placeholders\n";

$inliner = new \TijsVerkoyen\CssToInlineStyles\CssToInlineStyles();
$inlined = $inliner->convert($htmlProtected, $css) ?: $htmlProtected;

// Verificar se os placeholders sobreviveram
$survivedCount = 0;
foreach ($imgMap as $key => $tag) {
    if (str_contains($inlined, $key)) {
        $survivedCount++;
    } else {
        echo "  ❌ Placeholder PERDIDO: {$key}\n";
    }
}
echo "  Placeholders que sobreviveram: {$survivedCount}/" . count($imgMap) . "\n";

// Restaurar
foreach ($imgMap as $key => $originalTag) {
    $inlined = str_replace($key, $originalTag, $inlined);
}

preg_match_all('/<img[^>]*>/i', $inlined, $m1);
foreach ($m1[0] as $i => $tag) {
    $hasSrc = preg_match('/src=/i', $tag);
    echo "  IMG #{$i}: " . ($hasSrc ? '✅ TEM src' : '❌ SEM src') . "\n";
    echo "    " . substr($tag, 0, 300) . "\n\n";
}

// STEP 2: Testar sem inliner (direto embed)
echo "\nSTEP 2 — embedImagesAsBase64 direto (sem inliner):\n";
$campaignService = app(\App\Services\Email\EmailCampaignService::class);
$htmlEmbedded = $campaignService->embedImagesAsBase64($html);

preg_match_all('/<img[^>]*>/i', $htmlEmbedded, $m2);
foreach ($m2[0] as $i => $tag) {
    $hasSrc = preg_match('/src=/i', $tag);
    echo "  IMG #{$i}: " . ($hasSrc ? '✅ TEM src' : '❌ SEM src') . "\n";
    echo "    " . substr($tag, 0, 200) . "\n\n";
}

// STEP 3: Pipeline completo (inliner + embed)
echo "\nSTEP 3 — Pipeline completo (inliner + embed):\n";
$htmlFinal = $campaignService->embedImagesAsBase64($inlined);

preg_match_all('/<img[^>]*>/i', $htmlFinal, $m3);
foreach ($m3[0] as $i => $tag) {
    $hasSrc = preg_match('/src=/i', $tag);
    $isBase64 = preg_match('/src=["\']data:/', $tag);
    echo "  IMG #{$i}: " . ($isBase64 ? '✅ BASE64' : ($hasSrc ? '⚠️ URL (não convertida)' : '❌ SEM src')) . "\n";
    echo "    " . substr($tag, 0, 200) . "\n\n";
}

echo "=== FIM DO DIAGNÓSTICO ===\n";

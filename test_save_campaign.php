<?php
/**
 * Script de teste para verificar erro ao salvar campanha
 * Execute: php test_save_campaign.php
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Email\EmailImageService;

echo "=== TESTE DE SERVIÇO DE IMAGENS ===\n\n";

try {
    $service = app(EmailImageService::class);
    echo "✅ Serviço EmailImageService instanciado com sucesso\n";
    
    // Testar processamento de HTML simples
    $html = '<img src="https://placehold.co/600x300/6366f1/ffffff?text=Teste">';
    echo "\nTestando processamento de HTML...\n";
    echo "HTML original: " . substr($html, 0, 80) . "...\n";
    
    $result = $service->processHtmlAndStoreImages($html, 1, null);
    echo "✅ HTML processado: " . substr($result, 0, 80) . "...\n";
    
} catch (Exception $e) {
    echo "\n❌ ERRO: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . "\n";
    echo "Linha: " . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== FIM DO TESTE ===\n";

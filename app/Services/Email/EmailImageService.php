<?php

namespace App\Services\Email;

use App\Models\EmailAsset;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Serviço para processar e armazenar imagens de emails localmente
 * Garante que todas as imagens (externas ou internas) sejam armazenadas no servidor
 */
class EmailImageService
{
    private SvgConverterService $svgConverter;

    public function __construct()
    {
        $this->svgConverter = new SvgConverterService();
    }

    /**
     * Processa o HTML de uma campanha, baixando todas as imagens externas
     * e convertendo-as para assets locais. Converte SVG para PNG automaticamente.
     *
     * @param string $html O conteúdo HTML da campanha
     * @param int $brandId ID da marca para organização
     * @param int|null $userId ID do usuário que está salvando
     * @return string HTML com URLs de imagens atualizadas para caminhos locais
     */
    public function processHtmlAndStoreImages(string $html, int $brandId, ?int $userId = null): string
    {
        if (empty($html)) {
            SystemLog::debug('email', 'image.process_empty', "HTML vazio, nada para processar", [
                'brand_id' => $brandId,
            ]);
            return $html;
        }

        SystemLog::info('email', 'image.process_start', "Iniciando processamento de imagens no HTML", [
            'brand_id' => $brandId,
            'html_length' => strlen($html),
            'user_id' => $userId,
        ]);

        // Encontra todas as imagens primeiro (para logging)
        $allImages = $this->findExternalImages($html);
        SystemLog::info('email', 'image.found_external', "Imagens externas encontradas", [
            'brand_id' => $brandId,
            'count' => count($allImages),
            'urls' => array_slice($allImages, 0, 5), // Primeiras 5
        ]);

        $processedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        // Padrão para encontrar todas as imagens com src
        $pattern = '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i';

        $result = preg_replace_callback($pattern, function ($matches) use ($brandId, $userId, &$processedCount, &$skippedCount, &$failedCount) {
            $originalTag = $matches[0];
            $src = $matches[1];

            SystemLog::debug('email', 'image.processing_tag', "Processando tag img", [
                'src' => $src,
                'brand_id' => $brandId,
            ]);

            // Se já é base64, não processa
            if (str_starts_with($src, 'data:')) {
                $skippedCount++;
                SystemLog::debug('email', 'image.skip_base64', "Pulando imagem base64", ['src' => substr($src, 0, 50)]);
                return $originalTag;
            }

            // Se já é um caminho local nosso, não processa
            if (str_starts_with($src, '/storage/email-assets/')) {
                $skippedCount++;
                SystemLog::debug('email', 'image.skip_local', "Pulando imagem já local", ['src' => $src]);
                return $originalTag;
            }

            // Se é URL externa (http/https), baixa e armazena localmente
            if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
                SystemLog::info('email', 'image.download_start', "Baixando imagem externa", [
                    'url' => $src,
                    'brand_id' => $brandId,
                ]);

                $localUrl = $this->downloadAndStoreImage($src, $brandId, $userId);

                if ($localUrl) {
                    $processedCount++;
                    SystemLog::info('email', 'image.download_success', "Imagem baixada com sucesso", [
                        'original_url' => $src,
                        'local_url' => $localUrl,
                        'brand_id' => $brandId,
                    ]);
                    // Substitui a URL original pela URL local
                    return str_replace($src, $localUrl, $originalTag);
                }

                $failedCount++;
                // Se falhou em baixar, mantém a original mas loga
                SystemLog::warning('email', 'image.download_failed', "Não foi possível baixar imagem externa", [
                    'brand_id' => $brandId,
                    'original_url' => $src,
                ]);

                return $originalTag;
            }

            // Se é caminho relativo /storage/ (mas não email-assets)
            if (str_starts_with($src, '/storage/')) {
                $skippedCount++;
                SystemLog::debug('email', 'image.skip_storage', "Pulando imagem em /storage/", ['src' => $src]);
                return $originalTag;
            }

            // Qualquer outro caso
            $skippedCount++;
            SystemLog::debug('email', 'image.skip_other', "Pulando imagem (caso não tratado)", ['src' => $src]);
            return $originalTag;
        }, $html);

        SystemLog::info('email', 'image.process_complete', "Processamento de imagens finalizado", [
            'brand_id' => $brandId,
            'total_found' => count($allImages),
            'processed' => $processedCount,
            'skipped' => $skippedCount,
            'failed' => $failedCount,
        ]);

        return $result;
    }

    /**
     * Baixa uma imagem de URL externa e armazena localmente
     *
     * @param string $url URL da imagem externa
     * @param int $brandId ID da marca
     * @param int|null $userId ID do usuário
     * @return string|null URL local da imagem armazenada ou null se falhar
     */
    public function downloadAndStoreImage(string $url, int $brandId, ?int $userId = null): ?string
    {
        try {
            SystemLog::info('email', 'image.download_step', "Iniciando download", [
                'url' => $url,
                'brand_id' => $brandId,
            ]);

            // Verifica se a URL é válida
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                SystemLog::warning('email', 'image.invalid_url', "URL inválida", ['url' => $url]);
                return null;
            }

            // Não baixa imagens do próprio domínio (já estão locais)
            $appUrl = config('app.url');
            if (str_starts_with($url, $appUrl)) {
                $relativePath = str_replace($appUrl . '/storage/', '', $url);
                if (Storage::disk('public')->exists($relativePath)) {
                    // Verifica se é SVG e precisa de conversão
                    if (str_ends_with(strtolower($relativePath), '.svg')) {
                        SystemLog::info('email', 'image.local_svg_detected', "SVG local detectado, verificando conversão", [
                            'url' => $url,
                            'path' => $relativePath,
                        ]);

                        // Verifica se existe PNG equivalente
                        $pngPath = str_replace('.svg', '.png', $relativePath);
                        if (Storage::disk('public')->exists($pngPath)) {
                            SystemLog::info('email', 'image.local_svg_png_exists', "PNG já existe para SVG local", [
                                'svg_path' => $relativePath,
                                'png_path' => $pngPath,
                            ]);
                            return Storage::disk('public')->url($pngPath);
                        }

                        // Tenta converter o SVG local
                        $convertedPngPath = $this->svgConverter->convertToPng($relativePath);

                        if ($convertedPngPath) {
                            SystemLog::info('email', 'image.local_svg_converted', "SVG local convertido com sucesso", [
                                'svg_path' => $relativePath,
                                'png_path' => $convertedPngPath,
                            ]);

                            // Atualiza o asset no banco se existir
                            try {
                                $asset = EmailAsset::where('file_path', $relativePath)->first();
                                if ($asset) {
                                    $fullPngPath = Storage::disk('public')->path($convertedPngPath);
                                    $imageContent = file_get_contents($fullPngPath);
                                    $img = getimagesizefromstring($imageContent);

                                    $asset->update([
                                        'file_path' => $convertedPngPath,
                                        'mime_type' => 'image/png',
                                        'file_size' => strlen($imageContent),
                                        'dimensions' => $img ? ['width' => $img[0], 'height' => $img[1]] : null,
                                    ]);
                                }
                            } catch (\Throwable $e) {
                                // Ignora erro na atualização do banco
                            }

                            return Storage::disk('public')->url($convertedPngPath);
                        }

                        // Falhou na conversão - não deve usar SVG
                        SystemLog::warning('email', 'image.local_svg_conversion_failed', "Falha ao converter SVG local", [
                            'url' => $url,
                            'path' => $relativePath,
                        ]);
                        return null;
                    }

                    SystemLog::info('email', 'image.already_local', "Imagem já está local", ['url' => $url]);
                    return $url;
                }
            }

            // Verifica se já baixamos esta imagem antes (evita duplicatas)
            try {
                $existingAsset = EmailAsset::where('source_url', $url)
                    ->where('brand_id', $brandId)
                    ->first();

                if ($existingAsset) {
                    // Se for SVG, verifica se já foi convertido para PNG
                    if ($existingAsset->mime_type === 'image/svg+xml' || str_ends_with($existingAsset->file_path, '.svg')) {
                        SystemLog::info('email', 'image.svg_already_exists', "SVG já existe, verificando conversão", [
                            'url' => $url,
                            'existing_path' => $existingAsset->file_path,
                        ]);

                        // Verifica se existe um PNG com mesmo nome base
                        $pngPath = str_replace('.svg', '.png', $existingAsset->file_path);
                        if (Storage::disk('public')->exists($pngPath)) {
                            SystemLog::info('email', 'image.svg_png_exists', "PNG já existe para este SVG", [
                                'svg_path' => $existingAsset->file_path,
                                'png_path' => $pngPath,
                            ]);
                            return Storage::disk('public')->url($pngPath);
                        }

                        // Tenta converter o SVG existente
                        SystemLog::info('email', 'image.svg_converting_existing', "Tentando converter SVG existente", [
                            'path' => $existingAsset->file_path,
                        ]);

                        $convertedPngPath = $this->svgConverter->convertToPng($existingAsset->file_path);

                        if ($convertedPngPath) {
                            // Atualiza o asset para apontar para o PNG
                            $fullPngPath = Storage::disk('public')->path($convertedPngPath);
                            $imageContent = file_get_contents($fullPngPath);
                            $img = getimagesizefromstring($imageContent);
                            $dimensions = $img ? ['width' => $img[0], 'height' => $img[1]] : null;

                            $existingAsset->update([
                                'file_path' => $convertedPngPath,
                                'mime_type' => 'image/png',
                                'file_size' => strlen($imageContent),
                                'dimensions' => $dimensions,
                            ]);

                            SystemLog::info('email', 'image.svg_converted_existing', "SVG existente convertido com sucesso", [
                                'svg_path' => $existingAsset->file_path,
                                'png_path' => $convertedPngPath,
                            ]);

                            return Storage::disk('public')->url($convertedPngPath);
                        }

                        // Se falhou na conversão, retorna null (não deve usar SVG)
                        SystemLog::warning('email', 'image.svg_conversion_failed_existing', "Falha ao converter SVG existente", [
                            'url' => $url,
                            'path' => $existingAsset->file_path,
                        ]);
                        return null;
                    }

                    SystemLog::info('email', 'image.already_downloaded', "Imagem já baixada anteriormente", [
                        'url' => $url,
                        'local_url' => $existingAsset->url,
                    ]);
                    return $existingAsset->url;
                }
            } catch (\Throwable $e) {
                SystemLog::warning('email', 'image.duplicate_check_failed', "Erro ao verificar duplicata", [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                // Continua com download mesmo se falhar a verificação
            }

            SystemLog::info('email', 'image.downloading', "Baixando imagem...", ['url' => $url]);

            // Baixa o conteúdo da imagem
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'Mozilla/5.0 (Email Marketing Bot)',
                ],
            ]);

            $imageContent = @file_get_contents($url, false, $context);

            if ($imageContent === false) {
                $error = error_get_last();
                SystemLog::error('email', 'image.download_error', "Falha no download", [
                    'url' => $url,
                    'error' => $error['message'] ?? 'Unknown error',
                ]);
                return null;
            }

            if (!$imageContent || strlen($imageContent) < 100) {
                SystemLog::warning('email', 'image.empty_download', "Imagem baixada está vazia ou muito pequena", [
                    'url' => $url,
                    'size' => strlen($imageContent ?? ''),
                ]);
                return null;
            }

            SystemLog::info('email', 'image.downloaded', "Download concluído", [
                'url' => $url,
                'size' => strlen($imageContent),
            ]);

            // Detecta o mime type
            $mimeType = $this->detectMimeType($imageContent, $url);
            SystemLog::info('email', 'image.mime_detected', "MIME type detectado", [
                'url' => $url,
                'mime_type' => $mimeType,
            ]);

            // Valida que é uma imagem
            if (!str_starts_with($mimeType, 'image/')) {
                SystemLog::warning('email', 'image.invalid_mime', "Arquivo não é uma imagem válida", [
                    'url' => $url,
                    'mime_type' => $mimeType,
                ]);
                return null;
            }

            // Gera nome único para o arquivo
            $extension = $this->getExtensionFromMimeType($mimeType) ?? 'png';
            $fileName = 'email_' . Str::random(16) . '_' . time() . '.' . $extension;
            $path = "email-assets/{$brandId}/" . date('Y/m');
            $fullPath = $path . '/' . $fileName;

            SystemLog::info('email', 'image.saving_file', "Salvando arquivo no storage", [
                'url' => $url,
                'path' => $fullPath,
                'size' => strlen($imageContent),
            ]);

            // Armazena no disco público
            Storage::disk('public')->makeDirectory($path);
            $saved = Storage::disk('public')->put($fullPath, $imageContent);

            if (!$saved) {
                SystemLog::error('email', 'image.save_failed', "Falha ao salvar arquivo no storage", [
                    'url' => $url,
                    'path' => $fullPath,
                ]);
                return null;
            }

            SystemLog::info('email', 'image.file_saved', "Arquivo salvo com sucesso", ['path' => $fullPath]);

            // Detecta dimensões
            $dimensions = null;
            try {
                $img = getimagesizefromstring($imageContent);
                if ($img) {
                    $dimensions = ['width' => $img[0], 'height' => $img[1]];
                }
            } catch (\Throwable $e) {
                // Ignora erro de dimensão
            }

            // Se for SVG, tenta converter para PNG
            $finalPath = $fullPath;
            $finalMimeType = $mimeType;
            $finalExtension = $extension;
            $converted = false;

            if ($mimeType === 'image/svg+xml' || $extension === 'svg') {
                SystemLog::info('email', 'image.svg_detected', "SVG detectado, tentando converter para PNG", [
                    'url' => $url,
                    'path' => $fullPath,
                ]);

                $pngPath = $this->svgConverter->convertToPng($fullPath, $dimensions['width'] ?? null, $dimensions['height'] ?? null);

                if ($pngPath) {
                    // Conversão bem-sucedida, usar o PNG
                    $finalPath = $pngPath;
                    $finalMimeType = 'image/png';
                    $finalExtension = 'png';
                    $converted = true;

                    // Obter tamanho do PNG
                    $fullPngPath = Storage::disk('public')->path($pngPath);
                    $imageContent = file_get_contents($fullPngPath);

                    SystemLog::info('email', 'image.svg_converted', "SVG convertido com sucesso para PNG", [
                        'svg_path' => $fullPath,
                        'png_path' => $pngPath,
                    ]);

                    // Atualizar dimensões se não tínhamos antes
                    if (!$dimensions) {
                        $img = getimagesizefromstring($imageContent);
                        if ($img) {
                            $dimensions = ['width' => $img[0], 'height' => $img[1]];
                        }
                    }
                } else {
                    // Falhou na conversão - tenta detectar dimensões SVG para criar um placeholder
                    SystemLog::warning('email', 'image.svg_conversion_failed', "Falha ao converter SVG. Clientes de email não suportam SVG.", [
                        'url' => $url,
                        'path' => $fullPath,
                    ]);

                    // Ainda assim salva o SVG, mas retorna null para que a imagem não seja usada
                    // ou retornamos o SVG e deixamos o embedImagesAsBase64 tratar
                    return null;
                }
            }

            // Cria registro no banco
            SystemLog::info('email', 'image.creating_asset', "Criando registro no banco", [
                'url' => $url,
                'path' => $finalPath,
                'converted' => $converted,
            ]);

            try {
                $asset = EmailAsset::create([
                    'brand_id' => $brandId,
                    'user_id' => $userId,
                    'file_path' => $finalPath,
                    'file_name' => basename($url),
                    'mime_type' => $finalMimeType,
                    'file_size' => strlen($imageContent),
                    'dimensions' => $dimensions,
                    'source_url' => $url,
                    'alt_text' => null,
                ]);

                SystemLog::info('email', 'image.asset_created', "Asset criado com sucesso", [
                    'asset_id' => $asset->id,
                    'url' => $asset->url,
                    'converted' => $converted,
                ]);

                return $asset->url;
            } catch (\Throwable $e) {
                SystemLog::error('email', 'image.asset_create_failed', "Erro ao criar asset", [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }

        } catch (\Throwable $e) {
            SystemLog::error('email', 'image.download_error', "Erro ao baixar imagem {$url}: {$e->getMessage()}", [
                'brand_id' => $brandId,
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Detecta o mime type do conteúdo da imagem
     */
    private function detectMimeType(string $content, string $originalUrl): string
    {
        // Tenta detectar pelo conteúdo
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);

        if ($mimeType && $mimeType !== 'application/octet-stream') {
            return $mimeType;
        }

        // Fallback: detecta pela extensão da URL
        $extension = pathinfo(parse_url($originalUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
        $extension = strtolower($extension);

        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
        ];

        return $mimeTypes[$extension] ?? 'image/png';
    }

    /**
     * Retorna a extensão baseada no mime type
     */
    private function getExtensionFromMimeType(string $mimeType): ?string
    {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp',
        ];

        return $extensions[$mimeType] ?? null;
    }

    /**
     * Verifica se todas as imagens no HTML estão armazenadas localmente
     * Retorna um array com URLs de imagens externas encontradas
     */
    public function findExternalImages(string $html): array
    {
        if (empty($html)) {
            return [];
        }

        $externalImages = [];
        $pattern = '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i';

        preg_match_all($pattern, $html, $matches);

        foreach ($matches[1] as $src) {
            // Pula base64
            if (str_starts_with($src, 'data:')) {
                continue;
            }

            // Pula URLs locais
            if (str_starts_with($src, '/storage/')) {
                continue;
            }

            // URL externa
            if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
                $externalImages[] = $src;
            }
        }

        return array_unique($externalImages);
    }
}

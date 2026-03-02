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
    /**
     * Processa o HTML de uma campanha, baixando todas as imagens externas
     * e convertendo-as para assets locais.
     *
     * @param string $html O conteúdo HTML da campanha
     * @param int $brandId ID da marca para organização
     * @param int|null $userId ID do usuário que está salvando
     * @return string HTML com URLs de imagens atualizadas para caminhos locais
     */
    public function processHtmlAndStoreImages(string $html, int $brandId, ?int $userId = null): string
    {
        if (empty($html)) {
            return $html;
        }

        // Padrão para encontrar todas as imagens com src
        $pattern = '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i';

        return preg_replace_callback($pattern, function ($matches) use ($brandId, $userId) {
            $originalTag = $matches[0];
            $src = $matches[1];

            // Se já é base64, não processa
            if (str_starts_with($src, 'data:')) {
                return $originalTag;
            }

            // Se já é um caminho local nosso, não processa
            if (str_starts_with($src, '/storage/email-assets/')) {
                return $originalTag;
            }

            // Se é URL externa (http/https), baixa e armazena localmente
            if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
                $localUrl = $this->downloadAndStoreImage($src, $brandId, $userId);

                if ($localUrl) {
                    // Substitui a URL original pela URL local
                    return str_replace($src, $localUrl, $originalTag);
                }

                // Se falhou em baixar, mantém a original mas loga
                SystemLog::warning('email', 'image.download_failed', "Não foi possível baixar imagem externa: {$src}", [
                    'brand_id' => $brandId,
                    'original_src' => $src,
                ]);

                return $originalTag;
            }

            // Se é caminho relativo /storage/ (mas não email-assets), converte
            if (str_starts_with($src, '/storage/') && !str_starts_with($src, '/storage/email-assets/')) {
                // Já está no storage público, apenas retorna
                return $originalTag;
            }

            return $originalTag;
        }, $html);
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
            // Verifica se a URL é válida
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return null;
            }

            // Não baixa imagens do próprio domínio (já estão locais)
            $appUrl = config('app.url');
            if (str_starts_with($url, $appUrl)) {
                // Extrai o path relativo
                $relativePath = str_replace($appUrl . '/storage/', '', $url);
                if (Storage::disk('public')->exists($relativePath)) {
                    return $url; // Já está local
                }
            }

            // Verifica se já baixamos esta imagem antes (evita duplicatas)
            $existingAsset = EmailAsset::where('source_url', $url)
                ->where('brand_id', $brandId)
                ->first();

            if ($existingAsset) {
                return $existingAsset->url;
            }

            // Baixa o conteúdo da imagem
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'Mozilla/5.0 (Email Marketing Bot)',
                ],
            ]);

            $imageContent = @file_get_contents($url, false, $context);

            if (!$imageContent || strlen($imageContent) < 100) {
                SystemLog::warning('email', 'image.empty_download', "Imagem baixada está vazia ou muito pequena: {$url}");
                return null;
            }

            // Detecta o mime type
            $mimeType = $this->detectMimeType($imageContent, $url);

            // Valida que é uma imagem
            if (!str_starts_with($mimeType, 'image/')) {
                SystemLog::warning('email', 'image.invalid_mime', "Arquivo não é uma imagem válida: {$mimeType}", [
                    'url' => $url,
                ]);
                return null;
            }

            // Gera nome único para o arquivo
            $extension = $this->getExtensionFromMimeType($mimeType) ?? 'png';
            $fileName = 'email_' . Str::random(16) . '_' . time() . '.' . $extension;
            $path = "email-assets/{$brandId}/" . date('Y/m');
            $fullPath = $path . '/' . $fileName;

            // Armazena no disco público
            Storage::disk('public')->makeDirectory($path);
            Storage::disk('public')->put($fullPath, $imageContent);

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

            // Cria registro no banco
            $asset = EmailAsset::create([
                'brand_id' => $brandId,
                'user_id' => $userId,
                'file_path' => $fullPath,
                'file_name' => basename($url),
                'mime_type' => $mimeType,
                'file_size' => strlen($imageContent),
                'dimensions' => $dimensions,
                'source_url' => $url, // Guarda a URL original para referência
                'alt_text' => null,
            ]);

            SystemLog::info('email', 'image.downloaded', "Imagem externa baixada e armazenada: {$url} -> {$asset->url}", [
                'brand_id' => $brandId,
                'original_url' => $url,
                'local_path' => $fullPath,
                'asset_id' => $asset->id,
            ]);

            return $asset->url;

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

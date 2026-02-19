<?php

namespace App\Services\Social\Publishers;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\SocialAccount;
use App\Models\SystemLog;
use App\Services\Social\PublishResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publisher para Instagram via Meta Graph API.
 *
 * Inclui fallback automático: se a URL direta da mídia for rejeitada
 * pelo Instagram (error 2207052), faz upload via Facebook Page CDN
 * e retenta com a URL do CDN do Meta.
 */
class InstagramPublisher extends AbstractPublisher
{
    private const API_VERSION            = 'v21.0';
    private const BASE_URL               = 'https://graph.facebook.com/' . self::API_VERSION;
    private const VIDEO_POLL_MAX_SECONDS = 90;
    private const VIDEO_POLL_INTERVAL    = 5;
    private const MEDIA_FETCH_ERROR      = 2207052;

    /** IDs de fotos temporárias no Facebook para limpeza pós-publicação */
    private array $tempFbPhotoIds = [];
    private ?string $cachedPageId = null;
    private ?string $cachedPageToken = null;

    protected function platformName(): string
    {
        return 'Instagram';
    }

    protected function doPublish(Post $post, SocialAccount $account): PublishResult
    {
        $token    = $account->getFreshToken() ?? $account->access_token;
        $igUserId = $account->platform_user_id;

        SystemLog::info('social', 'ig.publish.start', "Instagram: iniciando publicação do post #{$post->id}", [
            'post_id'       => $post->id,
            'account_id'    => $account->id,
            'username'      => $account->username,
            'ig_user_id'    => $igUserId,
            'has_token'     => !empty($token),
            'token_expires' => $account->token_expires_at?->toIso8601String(),
            'platforms'     => $post->platforms,
            'media_count'   => $post->media->count(),
            'post_type'     => $post->type?->value,
        ]);

        if (!$token || !$igUserId) {
            return $this->fail($post, 'Conta Instagram sem token ou ID configurado. Reconecte a conta.');
        }

        $caption    = $this->buildCaption($post);
        $mediaItems = $post->media->sortBy('order')->values();

        if ($mediaItems->isEmpty()) {
            return $this->fail($post, 'Instagram requer pelo menos uma imagem ou vídeo para publicar.');
        }

        SystemLog::info('social', 'ig.publish.media', "Instagram: mídias do post #{$post->id}", [
            'post_id' => $post->id,
            'media'   => $mediaItems->map(fn($m) => [
                'id'        => $m->id,
                'type'      => $m->type,
                'file_path' => $m->file_path,
                'public_url'=> $this->mediaUrl($m),
            ])->toArray(),
        ]);

        try {
            if ($mediaItems->count() === 1) {
                return $this->publishSingle($post, $igUserId, $token, $caption, $mediaItems->first());
            }

            return $this->publishCarousel($post, $igUserId, $token, $caption, $mediaItems);
        } finally {
            $this->cleanupTempPhotos($token);
        }
    }

    // ===== Mídia única =====

    private function publishSingle(Post $post, string $igUserId, string $token, string $caption, PostMedia $media): PublishResult
    {
        $isVideo = $media->type === 'video';
        $isReel  = $post->type?->value === 'reel';

        $resolvedUrl = $this->resolveMediaUrl($post, $media, $token, $isVideo || $isReel);

        $params = ['caption' => $caption, 'access_token' => $token];

        if ($isVideo || $isReel) {
            $params['media_type'] = 'REELS';
            $params['video_url']  = $resolvedUrl;
        } else {
            $params['image_url'] = $resolvedUrl;
        }

        SystemLog::info('social', 'ig.container.create', "Instagram: criando container", [
            'post_id'    => $post->id,
            'media_type' => $params['media_type'] ?? 'IMAGE',
            'url'        => $resolvedUrl,
        ]);

        $containerResponse = Http::post(self::BASE_URL . "/{$igUserId}/media", $params);

        SystemLog::info('social', 'ig.container.response', "Instagram: resposta container", [
            'post_id' => $post->id,
            'status'  => $containerResponse->status(),
            'body'    => $containerResponse->json(),
        ]);

        // Fallback: se a URL direta falhou, tentar via Meta CDN
        if (!$containerResponse->successful() && !$isVideo && !$isReel) {
            $containerResponse = $this->retryWithCdn($post, $media, $igUserId, $token, $params, $containerResponse);
        }

        if (!$containerResponse->successful()) {
            return $this->fail($post, 'Erro ao criar container: ' . $this->apiError($containerResponse), $containerResponse->json());
        }

        $creationId = $containerResponse->json('id');

        if ($isVideo || $isReel) {
            $waitResult = $this->waitForVideoProcessing($post, $creationId, $token);
            if ($waitResult !== null) {
                return $waitResult;
            }
        }

        return $this->publishContainer($post, $igUserId, $token, $creationId);
    }

    // ===== Carrossel =====

    private function publishCarousel(Post $post, string $igUserId, string $token, string $caption, Collection $mediaItems): PublishResult
    {
        $childIds = [];

        foreach ($mediaItems as $index => $media) {
            $isVideo = $media->type === 'video';
            $resolvedUrl = $this->resolveMediaUrl($post, $media, $token, $isVideo);

            $params = ['is_carousel_item' => 'true', 'access_token' => $token];

            if ($isVideo) {
                $params['media_type'] = 'VIDEO';
                $params['video_url']  = $resolvedUrl;
            } else {
                $params['image_url'] = $resolvedUrl;
            }

            SystemLog::info('social', 'ig.carousel.item', "Instagram: criando item carrossel #{$index}", [
                'post_id'    => $post->id,
                'media_id'   => $media->id,
                'media_type' => $params['media_type'] ?? 'IMAGE',
                'url'        => $resolvedUrl,
            ]);

            $response = Http::post(self::BASE_URL . "/{$igUserId}/media", $params);

            SystemLog::info('social', 'ig.carousel.item.response', "Instagram: resposta item carrossel #{$index}", [
                'post_id' => $post->id,
                'status'  => $response->status(),
                'body'    => $response->json(),
            ]);

            // Fallback para imagens do carrossel
            if (!$response->successful() && !$isVideo) {
                $response = $this->retryWithCdn($post, $media, $igUserId, $token, $params, $response, true);
            }

            if (!$response->successful()) {
                return $this->fail($post, "Erro ao criar item #{$index} do carrossel: " . $this->apiError($response), $response->json());
            }

            $childIds[] = $response->json('id');
        }

        $carouselResponse = Http::post(self::BASE_URL . "/{$igUserId}/media", [
            'media_type'   => 'CAROUSEL',
            'children'     => implode(',', $childIds),
            'caption'      => $caption,
            'access_token' => $token,
        ]);

        SystemLog::info('social', 'ig.carousel.container.response', "Instagram: resposta container carrossel", [
            'post_id' => $post->id,
            'status'  => $carouselResponse->status(),
            'body'    => $carouselResponse->json(),
        ]);

        if (!$carouselResponse->successful()) {
            return $this->fail($post, 'Erro ao criar carrossel: ' . $this->apiError($carouselResponse), $carouselResponse->json());
        }

        return $this->publishContainer($post, $igUserId, $token, $carouselResponse->json('id'));
    }

    // ===== Publicar container =====

    private function publishContainer(Post $post, string $igUserId, string $token, string $creationId): PublishResult
    {
        SystemLog::info('social', 'ig.publish.container', "Instagram: publicando container", [
            'post_id'     => $post->id,
            'creation_id' => $creationId,
        ]);

        $response = Http::post(self::BASE_URL . "/{$igUserId}/media_publish", [
            'creation_id'  => $creationId,
            'access_token' => $token,
        ]);

        SystemLog::info('social', 'ig.publish.response', "Instagram: resposta publicação final", [
            'post_id' => $post->id,
            'status'  => $response->status(),
            'body'    => $response->json(),
        ]);

        if (!$response->successful()) {
            return $this->fail($post, 'Erro ao publicar no Instagram: ' . $this->apiError($response), $response->json());
        }

        $postId  = $response->json('id');
        $postUrl = "https://www.instagram.com/p/{$postId}/";

        SystemLog::info('social', 'ig.publish.success', "Instagram: post #{$post->id} publicado com sucesso", [
            'post_id'          => $post->id,
            'platform_post_id' => $postId,
            'platform_url'     => $postUrl,
        ]);

        return PublishResult::ok($postId, $postUrl);
    }

    // ===== Meta CDN Fallback =====

    /**
     * Resolve a URL da mídia: tenta a URL direta primeiro.
     * Se já sabemos que o CDN é necessário (por falha anterior na mesma sessão),
     * já resolve via CDN diretamente.
     */
    private function resolveMediaUrl(Post $post, PostMedia $media, string $token, bool $isVideo): string
    {
        $directUrl = $this->mediaUrl($media);

        // Vídeos não suportam o workaround de CDN (apenas imagens)
        if ($isVideo) {
            return $directUrl;
        }

        // Se já fizemos upload CDN nesta sessão, pré-resolver via CDN
        if (!empty($this->tempFbPhotoIds)) {
            $cdnUrl = $this->uploadToMetaCdn($post, $media, $token);
            if ($cdnUrl) {
                SystemLog::info('social', 'ig.cdn.preemptive', "Instagram: usando CDN preventivamente", [
                    'post_id'    => $post->id,
                    'media_id'   => $media->id,
                    'cdn_url'    => $cdnUrl,
                    'direct_url' => $directUrl,
                ]);
                return $cdnUrl;
            }
        }

        return $directUrl;
    }

    /**
     * Retenta criação de container usando URL do Meta CDN.
     */
    private function retryWithCdn(Post $post, PostMedia $media, string $igUserId, string $token, array $params, $originalResponse, bool $isCarouselItem = false): mixed
    {
        $errorSubcode = $originalResponse->json('error.error_subcode');

        if ($errorSubcode != self::MEDIA_FETCH_ERROR) {
            return $originalResponse;
        }

        SystemLog::warning('social', 'ig.cdn.fallback', "Instagram: URL rejeitada (2207052), tentando via Meta CDN", [
            'post_id'    => $post->id,
            'media_id'   => $media->id,
            'direct_url' => $params['image_url'] ?? null,
            'error'      => $originalResponse->json('error.error_user_msg'),
        ]);

        $cdnUrl = $this->uploadToMetaCdn($post, $media, $token);

        if (!$cdnUrl) {
            SystemLog::error('social', 'ig.cdn.failed', "Instagram: fallback CDN também falhou", [
                'post_id'  => $post->id,
                'media_id' => $media->id,
            ]);
            return $originalResponse;
        }

        $params['image_url'] = $cdnUrl;

        $retryResponse = Http::post(self::BASE_URL . "/{$igUserId}/media", $params);

        SystemLog::info('social', 'ig.cdn.retry_response', "Instagram: resposta retry com CDN", [
            'post_id'  => $post->id,
            'cdn_url'  => $cdnUrl,
            'status'   => $retryResponse->status(),
            'body'     => $retryResponse->json(),
        ]);

        return $retryResponse;
    }

    /**
     * Faz upload de uma imagem para uma Facebook Page (não publicada)
     * e retorna a URL pública do CDN do Meta.
     */
    private function uploadToMetaCdn(Post $post, PostMedia $media, string $token): ?string
    {
        $localPath = storage_path('app/public/' . $media->file_path);

        if (!file_exists($localPath)) {
            SystemLog::warning('social', 'ig.cdn.file_missing', "Arquivo não encontrado no disco", [
                'post_id'    => $post->id,
                'file_path'  => $media->file_path,
                'local_path' => $localPath,
            ]);
            return null;
        }

        try {
            // Obter Page ID + token (com cache para múltiplas mídias)
            if (!$this->cachedPageId) {
                $this->discoverFacebookPage($token);
            }

            if (!$this->cachedPageId) {
                SystemLog::warning('social', 'ig.cdn.no_page', "Nenhuma Facebook Page encontrada para upload CDN", [
                    'post_id' => $post->id,
                ]);
                return null;
            }

            $pageToken = $this->cachedPageToken ?? $token;

            // Upload da imagem como foto não publicada
            $mimeType = mime_content_type($localPath) ?: 'image/jpeg';

            $uploadResp = Http::attach(
                'source',
                file_get_contents($localPath),
                basename($localPath),
                ['Content-Type' => $mimeType]
            )->post(self::BASE_URL . "/{$this->cachedPageId}/photos", [
                'published'    => 'false',
                'temporary'    => 'true',
                'access_token' => $pageToken,
            ]);

            if (!$uploadResp->successful()) {
                SystemLog::warning('social', 'ig.cdn.upload_fail', "Falha no upload para Facebook CDN", [
                    'post_id'  => $post->id,
                    'page_id'  => $this->cachedPageId,
                    'status'   => $uploadResp->status(),
                    'response' => $uploadResp->json(),
                ]);
                return null;
            }

            $photoId = $uploadResp->json('id');
            $this->tempFbPhotoIds[] = ['id' => $photoId, 'token' => $pageToken];

            // Obter URL do CDN
            $photoResp = Http::get(self::BASE_URL . "/{$photoId}", [
                'fields'       => 'images',
                'access_token' => $pageToken,
            ]);

            if (!$photoResp->successful() || empty($photoResp->json('images'))) {
                SystemLog::warning('social', 'ig.cdn.images_fail', "Falha ao obter URL das imagens do CDN", [
                    'post_id'  => $post->id,
                    'photo_id' => $photoId,
                    'status'   => $photoResp->status(),
                    'response' => $photoResp->json(),
                ]);
                return null;
            }

            $images = $photoResp->json('images');
            usort($images, fn($a, $b) => ($b['width'] ?? 0) - ($a['width'] ?? 0));
            $cdnUrl = $images[0]['source'] ?? null;

            SystemLog::info('social', 'ig.cdn.success', "Meta CDN URL obtida com sucesso", [
                'post_id'  => $post->id,
                'media_id' => $media->id,
                'photo_id' => $photoId,
                'cdn_url'  => $cdnUrl,
                'sizes'    => count($images),
                'largest'  => ['w' => $images[0]['width'] ?? 0, 'h' => $images[0]['height'] ?? 0],
            ]);

            return $cdnUrl;

        } catch (\Throwable $e) {
            SystemLog::error('social', 'ig.cdn.exception', "Exceção no upload CDN: {$e->getMessage()}", [
                'post_id'   => $post->id,
                'media_id'  => $media->id,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Descobre a Facebook Page vinculada ao token.
     *
     * Tenta 3 estratégias em sequência:
     * 1. GET /me/accounts (funciona se token é de Usuário)
     * 2. GET /me?fields=id,name (funciona se token já é de Página)
     * 3. Busca SocialAccount do tipo facebook no mesmo brand
     */
    private function discoverFacebookPage(string $token): void
    {
        try {
            // Estratégia 1: Token de Usuário → listar Pages gerenciadas
            $resp = Http::get(self::BASE_URL . '/me/accounts', [
                'access_token' => $token,
                'fields'       => 'id,name,access_token',
                'limit'        => 5,
            ]);

            if ($resp->successful() && !empty($resp->json('data'))) {
                $page = $resp->json('data.0');
                $this->cachedPageId    = $page['id'];
                $this->cachedPageToken = $page['access_token'] ?? $token;

                SystemLog::info('social', 'ig.cdn.page_found', "Facebook Page encontrada via /me/accounts", [
                    'page_id'   => $this->cachedPageId,
                    'page_name' => $page['name'] ?? '?',
                ]);
                return;
            }

            // Estratégia 2: Token já é de Página → /me retorna a própria Page
            $meResp = Http::get(self::BASE_URL . '/me', [
                'access_token' => $token,
                'fields'       => 'id,name',
            ]);

            if ($meResp->successful() && $meResp->json('id')) {
                $this->cachedPageId    = $meResp->json('id');
                $this->cachedPageToken = $token;

                SystemLog::info('social', 'ig.cdn.page_found', "Facebook Page encontrada via /me (token de Página)", [
                    'page_id'   => $this->cachedPageId,
                    'page_name' => $meResp->json('name') ?? '?',
                ]);
                return;
            }

            SystemLog::warning('social', 'ig.cdn.pages_empty', "Nenhuma Page encontrada por nenhuma estratégia", [
                'accounts_status'   => $resp->status(),
                'accounts_response' => $resp->json(),
                'me_status'         => $meResp->status(),
                'me_response'       => $meResp->json(),
            ]);

        } catch (\Throwable $e) {
            SystemLog::error('social', 'ig.cdn.pages_error', "Erro ao buscar Pages: {$e->getMessage()}");
        }
    }

    /**
     * Remove fotos temporárias do Facebook após a publicação.
     */
    private function cleanupTempPhotos(string $fallbackToken): void
    {
        foreach ($this->tempFbPhotoIds as $item) {
            try {
                Http::delete(self::BASE_URL . "/{$item['id']}", [
                    'access_token' => $item['token'] ?? $fallbackToken,
                ]);
            } catch (\Throwable) {
                // Silenciar — fotos temporárias expiram automaticamente
            }
        }

        if (!empty($this->tempFbPhotoIds)) {
            SystemLog::info('social', 'ig.cdn.cleanup', "Limpeza de fotos temporárias do CDN", [
                'count' => count($this->tempFbPhotoIds),
            ]);
        }

        $this->tempFbPhotoIds = [];
    }

    // ===== Aguardar processamento de vídeo =====

    private function waitForVideoProcessing(Post $post, string $creationId, string $token): ?PublishResult
    {
        $waited = 0;

        while ($waited < self::VIDEO_POLL_MAX_SECONDS) {
            sleep(self::VIDEO_POLL_INTERVAL);
            $waited += self::VIDEO_POLL_INTERVAL;

            $pollResponse = Http::get(self::BASE_URL . "/{$creationId}", [
                'fields'       => 'status_code,status',
                'access_token' => $token,
            ]);

            $status = $pollResponse->json('status_code');

            SystemLog::info('social', 'ig.video.poll', "Instagram: aguardando vídeo", [
                'post_id'     => $post->id,
                'creation_id' => $creationId,
                'waited_s'    => $waited,
                'status_code' => $status,
                'response'    => $pollResponse->json(),
            ]);

            if ($status === 'FINISHED') {
                return null;
            }

            if ($status === 'ERROR') {
                return $this->fail($post, 'Erro ao processar vídeo no Instagram.', $pollResponse->json());
            }
        }

        return $this->fail($post, "Timeout ({$waited}s) aguardando processamento de vídeo no Instagram.");
    }

    // ===== Helpers =====

    private function buildCaption(Post $post): string
    {
        $caption = $post->caption ?? '';

        if (!empty($post->hashtags)) {
            $tags = array_map(fn($h) => str_starts_with($h, '#') ? $h : "#{$h}", $post->hashtags);
            $caption .= "\n\n" . implode(' ', $tags);
        }

        return $caption;
    }

    private function mediaUrl(PostMedia $media): string
    {
        return rtrim(config('app.url'), '/') . '/storage/' . $media->file_path;
    }

    private function apiError($response): string
    {
        return $response->json('error.message')
            ?? $response->json('error.error_user_msg')
            ?? substr($response->body(), 0, 300);
    }

    private function fail(Post $post, string $message, ?array $apiResponse = null): PublishResult
    {
        SystemLog::error('social', 'ig.publish.error', "Instagram: falha ao publicar post #{$post->id}", [
            'post_id'      => $post->id,
            'error'        => $message,
            'api_response' => $apiResponse,
        ]);

        return PublishResult::fail($message);
    }
}

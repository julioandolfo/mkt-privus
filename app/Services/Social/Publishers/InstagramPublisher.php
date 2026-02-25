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
    private const VIDEO_POLL_MAX_SECONDS = 120;
    private const VIDEO_POLL_INTERVAL    = 5;
    private const MEDIA_FETCH_ERROR      = 2207052; // URL inacessível pelo Instagram
    private const MEDIA_TIMEOUT_ERROR    = 2207003; // Timeout ao baixar a mídia
    private const MEDIA_PROCESS_ERRORS   = [2207082, 2207053]; // Erros durante processamento/upload de vídeo

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

        // Validar URL e specs do vídeo antes de criar o container
        if ($isVideo || $isReel) {
            $urlCheck = $this->validateVideoUrl($post, $resolvedUrl);
            if ($urlCheck !== null) {
                return $urlCheck;
            }

            $specsCheck = $this->validateVideoSpecs($post, $media);
            if ($specsCheck !== null) {
                return $specsCheck;
            }
        }

        $params = ['caption' => $caption, 'access_token' => $token];

        if ($isVideo || $isReel) {
            $params['media_type']    = 'REELS';
            $params['video_url']     = $resolvedUrl;
            $params['share_to_feed'] = 'true';
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

        // Fallback CDN: se a URL direta falhou por inacessibilidade ou timeout
        $shouldTryCdn = !$isVideo && !$isReel && (
            !$containerResponse->successful() ||
            in_array($containerResponse->json('error.error_subcode'), [self::MEDIA_FETCH_ERROR, self::MEDIA_TIMEOUT_ERROR])
        );
        if ($shouldTryCdn) {
            $containerResponse = $this->retryWithCdn($post, $media, $igUserId, $token, $params, $containerResponse);
        }

        if (!$containerResponse->successful()) {
            return $this->fail($post, 'Erro ao criar container: ' . $this->apiError($containerResponse), $containerResponse->json());
        }

        $creationId = $containerResponse->json('id');

        // Aguardar container ficar pronto (imagens e vídeos)
        $waitResult = $this->waitForContainerReady($post, $creationId, $token, $isVideo || $isReel);
        if ($waitResult !== null) {
            return $waitResult;
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

            // Fallback CDN para imagens do carrossel (inacessibilidade ou timeout)
            $shouldTryCdnCarousel = !$isVideo && (
                !$response->successful() ||
                in_array($response->json('error.error_subcode'), [self::MEDIA_FETCH_ERROR, self::MEDIA_TIMEOUT_ERROR])
            );
            if ($shouldTryCdnCarousel) {
                $response = $this->retryWithCdn($post, $media, $igUserId, $token, $params, $response, true);
            }

            if (!$response->successful()) {
                return $this->fail($post, "Erro ao criar item #{$index} do carrossel: " . $this->apiError($response), $response->json());
            }

            $childId = $response->json('id');

            // Aguardar cada item ficar pronto antes de criar o carrossel
            $waitResult = $this->waitForContainerReady($post, $childId, $token, $isVideo);
            if ($waitResult !== null) {
                return $waitResult;
            }

            $childIds[] = $childId;
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

        $carouselId = $carouselResponse->json('id');

        // Aguardar container do carrossel ficar pronto
        $waitResult = $this->waitForContainerReady($post, $carouselId, $token, false);
        if ($waitResult !== null) {
            return $waitResult;
        }

        return $this->publishContainer($post, $igUserId, $token, $carouselId);
    }

    // ===== Publicar container =====

    private function publishContainer(Post $post, string $igUserId, string $token, string $creationId): PublishResult
    {
        SystemLog::info('social', 'ig.publish.container', "Instagram: publicando container", [
            'post_id'     => $post->id,
            'creation_id' => $creationId,
        ]);

        $maxRetries   = 5;
        $retryDelay   = 3;
        $lastResponse = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $response = Http::post(self::BASE_URL . "/{$igUserId}/media_publish", [
                'creation_id'  => $creationId,
                'access_token' => $token,
            ]);

            $lastResponse = $response;

            SystemLog::info('social', 'ig.publish.response', "Instagram: resposta publicação (tentativa {$attempt}/{$maxRetries})", [
                'post_id' => $post->id,
                'attempt' => $attempt,
                'status'  => $response->status(),
                'body'    => $response->json(),
            ]);

            if ($response->successful()) {
                break;
            }

            // "Media ID is not available" (2207027) — container ainda processando
            $subcode = $response->json('error.error_subcode');
            if ($subcode == 2207027 && $attempt < $maxRetries) {
                SystemLog::info('social', 'ig.publish.wait', "Instagram: container não pronto, aguardando {$retryDelay}s (tentativa {$attempt})", [
                    'post_id'     => $post->id,
                    'creation_id' => $creationId,
                ]);
                sleep($retryDelay);
                $retryDelay = min($retryDelay * 2, 15);
                continue;
            }

            break;
        }

        if (!$lastResponse->successful()) {
            return $this->fail($post, 'Erro ao publicar no Instagram: ' . $this->apiError($lastResponse), $lastResponse->json());
        }

        $postId  = $lastResponse->json('id');
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

        $cdnTriggerErrors = [self::MEDIA_FETCH_ERROR, self::MEDIA_TIMEOUT_ERROR];
        if (!in_array($errorSubcode, $cdnTriggerErrors)) {
            return $originalResponse;
        }

        SystemLog::warning('social', 'ig.cdn.fallback', "Instagram: URL rejeitada ({$errorSubcode}), tentando via Meta CDN", [
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

    // ===== Aguardar container ficar pronto =====

    /**
     * Polling do status do container antes de publicar.
     * Imagens vindas do CDN podem demorar alguns segundos para processar.
     * Vídeos podem demorar até 90 segundos.
     *
     * @return PublishResult|null  null = pronto para publicar
     */
    private function waitForContainerReady(Post $post, string $creationId, string $token, bool $isVideo): ?PublishResult
    {
        $maxWait  = $isVideo ? self::VIDEO_POLL_MAX_SECONDS : 30;
        $interval = $isVideo ? self::VIDEO_POLL_INTERVAL : 3;
        $waited   = 0;

        // Para imagens, dar um momento inicial para o container ser registrado
        if (!$isVideo) {
            sleep(2);
            $waited += 2;
        }

        while ($waited < $maxWait) {
            $pollResponse = Http::get(self::BASE_URL . "/{$creationId}", [
                'fields'       => 'status_code,status',
                'access_token' => $token,
            ]);

            $statusCode = $pollResponse->json('status_code');

            SystemLog::info('social', 'ig.container.poll', "Instagram: aguardando container ({$waited}s)", [
                'post_id'     => $post->id,
                'creation_id' => $creationId,
                'waited_s'    => $waited,
                'status_code' => $statusCode,
                'is_video'    => $isVideo,
            ]);

            if ($statusCode === 'FINISHED') {
                return null;
            }

            if ($statusCode === 'ERROR') {
                $errDetail  = $pollResponse->json('status') ?? 'Unknown processing error';
                $extraHint  = $this->getVideoProcessingHint($errDetail, $pollResponse->json());
                $fullMessage = "Erro ao processar mídia no Instagram: {$errDetail}{$extraHint}";
                return $this->fail($post, $fullMessage, $pollResponse->json());
            }

            if ($statusCode === 'EXPIRED') {
                return $this->fail($post, 'Container expirou antes de ser publicado.');
            }

            // IN_PROGRESS ou status desconhecido — continuar aguardando
            sleep($interval);
            $waited += $interval;
        }

        return $this->fail($post, "Timeout ({$waited}s) aguardando processamento de mídia no Instagram.");
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

    /**
     * Valida specs do vídeo com ffprobe antes de enviar ao Instagram.
     * Reels exigem: H.264 + AAC, proporção 9:16, duração 3-180s, mínimo 720px.
     *
     * @return PublishResult|null  null = specs OK
     */
    private function validateVideoSpecs(Post $post, PostMedia $media): ?PublishResult
    {
        $localPath = storage_path('app/public/' . $media->file_path);

        if (!file_exists($localPath)) {
            // Arquivo não está local (ex: produção com storage remoto) — pular validação
            return null;
        }

        // Verificar se ffprobe está disponível
        $ffprobeCheck = @shell_exec('ffprobe -version 2>&1');
        if (!$ffprobeCheck || !str_contains($ffprobeCheck, 'ffprobe version')) {
            return null; // ffprobe não disponível — pular validação
        }

        $cmd    = sprintf('ffprobe -v quiet -print_format json -show_streams -show_format %s 2>&1', escapeshellarg($localPath));
        $output = shell_exec($cmd);

        if (!$output) {
            return null;
        }

        $info    = json_decode($output, true);
        $streams = $info['streams'] ?? [];
        $format  = $info['format'] ?? [];

        $videoStream = collect($streams)->firstWhere('codec_type', 'video');
        $audioStream = collect($streams)->firstWhere('codec_type', 'audio');

        $errors = [];

        // Checar codec de vídeo (deve ser h264)
        $videoCodec = strtolower($videoStream['codec_name'] ?? '');
        if ($videoCodec && !in_array($videoCodec, ['h264', 'avc'])) {
            $errors[] = "codec de vídeo '{$videoCodec}' não suportado — use H.264 (x264)";
        }

        // Checar codec de áudio (deve ser aac)
        $audioCodec = strtolower($audioStream['codec_name'] ?? '');
        if ($audioStream && $audioCodec && $audioCodec !== 'aac') {
            $errors[] = "codec de áudio '{$audioCodec}' não suportado — use AAC";
        }

        // Checar duração (3-180 segundos)
        $duration = (float) ($format['duration'] ?? $videoStream['duration'] ?? 0);
        if ($duration > 0) {
            if ($duration < 3) {
                $errors[] = "duração {$duration}s muito curta — mínimo 3 segundos";
            } elseif ($duration > 180) {
                $errors[] = "duração {$duration}s muito longa — máximo 180 segundos";
            }
        }

        // Checar proporção (deve ser próximo de 9:16 = 0.5625 para Reels)
        $width  = (int) ($videoStream['width'] ?? 0);
        $height = (int) ($videoStream['height'] ?? 0);

        if ($width > 0 && $height > 0) {
            // Resolução mínima: 720px no lado menor
            $minDimension = min($width, $height);
            if ($minDimension < 500) {
                $errors[] = "resolução {$width}x{$height} muito baixa — mínimo 500px no menor lado";
            }

            // Proporção: aceitar entre 4:5 (0.8) e 9:16 (1.778) em modo portrait
            $ratio = $height > 0 ? $width / $height : 0;
            // Para Reels: idealmente portrait (width < height), ratio ~0.5625
            // Aceitar também landscape e square com aviso
            if ($ratio > 1.01) {
                $errors[] = sprintf(
                    "proporção %dx%d (landscape %.2f:1) — Reels preferem portrait 9:16 (1080×1920). O vídeo pode ser publicado mas será cortado pelo Instagram",
                    $width,
                    $height,
                    $ratio
                );
            }
        }

        SystemLog::info('social', 'ig.video.specs', "Instagram: specs do vídeo analisadas", [
            'post_id'     => $post->id,
            'media_id'    => $media->id,
            'video_codec' => $videoCodec ?: 'unknown',
            'audio_codec' => $audioCodec ?: 'none',
            'duration_s'  => $duration,
            'width'       => $width,
            'height'      => $height,
            'errors'      => $errors,
        ]);

        // Erros bloqueantes: codec errado ou duração fora do range
        $blocking = array_filter($errors, fn($e) => !str_contains($e, 'proporção') && !str_contains($e, 'landscape'));

        if (!empty($blocking)) {
            $message = "Vídeo incompatível com Instagram Reels: " . implode('; ', $blocking)
                . ". Use MP4 com H.264 + AAC, duração entre 3-180s e proporção 9:16.";
            return $this->fail($post, $message);
        }

        // Avisos não-bloqueantes (proporção landscape): logar mas continuar
        return null;
    }

    /**
     * Valida se a URL do vídeo é acessível publicamente via HTTPS.
     * O Instagram exige HTTPS e a URL deve ser acessível da internet.
     *
     * @return PublishResult|null  null = OK para prosseguir
     */
    private function validateVideoUrl(Post $post, string $url): ?PublishResult
    {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        $host   = strtolower($parsed['host'] ?? '');

        // Instagram exige HTTPS para vídeos
        if ($scheme !== 'https') {
            $message = "O Instagram exige HTTPS para vídeos/Reels. A URL atual usa '{$scheme}'. "
                . "Configure APP_URL com HTTPS no .env para publicações em produção.";

            SystemLog::error('social', 'ig.video.url_not_https', "Instagram: URL de vídeo não usa HTTPS", [
                'post_id' => $post->id,
                'url'     => $url,
                'scheme'  => $scheme,
            ]);

            return $this->fail($post, $message);
        }

        // Detectar domínios locais/privados inacessíveis pelo Instagram
        $localPatterns = ['localhost', '127.0.0.1', '::1', '.local', '.test', '.dev', '.internal'];
        foreach ($localPatterns as $pattern) {
            if ($host === $pattern || str_ends_with($host, $pattern)) {
                $message = "A URL do vídeo aponta para um domínio local ({$host}) que não é acessível pelos servidores do Instagram. "
                    . "Use um domínio público com HTTPS (ex: ngrok em desenvolvimento, ou o domínio de produção).";

                SystemLog::error('social', 'ig.video.url_local', "Instagram: URL de vídeo aponta para domínio local", [
                    'post_id' => $post->id,
                    'url'     => $url,
                    'host'    => $host,
                ]);

                return $this->fail($post, $message);
            }
        }

        return null;
    }

    /**
     * Retorna dica adicional baseada no código de erro retornado durante processamento de vídeo.
     */
    private function getVideoProcessingHint(string $status, ?array $response): string
    {
        // Detectar código de erro no campo status (ex: "error code 2207082")
        if (preg_match('/error code (\d+)/i', $status, $matches)) {
            $code = (int) $matches[1];

            if (in_array($code, self::MEDIA_PROCESS_ERRORS)) {
                return " | Causas comuns: (1) formato de vídeo incorreto — use MP4 com codec H.264 e áudio AAC; "
                    . "(2) proporção inválida — Reels exigem 9:16 (1080×1920px); "
                    . "(3) duração fora do range — mínimo 3s, máximo 180s; "
                    . "(4) URL do vídeo inacessível pelos servidores do Instagram (requer HTTPS público).";
            }

            if ($code === 2207026) {
                return " | Formato de vídeo não suportado. Use MP4 com H.264 + AAC.";
            }

            if ($code === 2207003) {
                return " | Timeout ao baixar o vídeo. Verifique se a URL é acessível e se o arquivo não é muito grande.";
            }
        }

        return '';
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

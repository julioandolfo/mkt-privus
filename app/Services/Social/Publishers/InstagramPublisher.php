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
 */
class InstagramPublisher extends AbstractPublisher
{
    private const API_VERSION            = 'v21.0';
    private const BASE_URL               = 'https://graph.facebook.com/' . self::API_VERSION;
    private const VIDEO_POLL_MAX_SECONDS = 90;
    private const VIDEO_POLL_INTERVAL    = 5;

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

        if ($mediaItems->count() === 1) {
            return $this->publishSingle($post, $igUserId, $token, $caption, $mediaItems->first());
        }

        return $this->publishCarousel($post, $igUserId, $token, $caption, $mediaItems);
    }

    // ===== Mídia única =====

    private function publishSingle(Post $post, string $igUserId, string $token, string $caption, PostMedia $media): PublishResult
    {
        $isVideo = $media->type === 'video';
        $isReel  = $post->type?->value === 'reel';

        $params = ['caption' => $caption, 'access_token' => $token];

        if ($isVideo || $isReel) {
            $params['media_type'] = 'REELS';
            $params['video_url']  = $this->mediaUrl($media);
        } else {
            $params['image_url'] = $this->mediaUrl($media);
        }

        SystemLog::info('social', 'ig.container.create', "Instagram: criando container", [
            'post_id'    => $post->id,
            'media_type' => $params['media_type'] ?? 'IMAGE',
            'url'        => $params['image_url'] ?? $params['video_url'] ?? null,
        ]);

        $containerResponse = Http::post(self::BASE_URL . "/{$igUserId}/media", $params);

        SystemLog::info('social', 'ig.container.response', "Instagram: resposta container", [
            'post_id' => $post->id,
            'status'  => $containerResponse->status(),
            'body'    => $containerResponse->json(),
        ]);

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
            $params = ['is_carousel_item' => 'true', 'access_token' => $token];

            if ($media->type === 'video') {
                $params['media_type'] = 'VIDEO';
                $params['video_url']  = $this->mediaUrl($media);
            } else {
                $params['image_url'] = $this->mediaUrl($media);
            }

            SystemLog::info('social', 'ig.carousel.item', "Instagram: criando item carrossel #{$index}", [
                'post_id'    => $post->id,
                'media_id'   => $media->id,
                'media_type' => $params['media_type'] ?? 'IMAGE',
                'url'        => $params['image_url'] ?? $params['video_url'] ?? null,
            ]);

            $response = Http::post(self::BASE_URL . "/{$igUserId}/media", $params);

            SystemLog::info('social', 'ig.carousel.item.response', "Instagram: resposta item carrossel #{$index}", [
                'post_id' => $post->id,
                'status'  => $response->status(),
                'body'    => $response->json(),
            ]);

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

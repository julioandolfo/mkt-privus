<?php

namespace App\Services\Social\Publishers;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\SocialAccount;
use App\Services\Social\PublishResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publisher para Instagram via Meta Graph API.
 *
 * Fluxo de publicação:
 *  1. Imagem única  → criar container → publicar
 *  2. Vídeo/Reel   → criar container → aguardar FINISHED → publicar
 *  3. Carrossel     → criar container por item → criar container carousel → publicar
 *  4. Sem mídia    → erro (Instagram exige imagem/vídeo)
 */
class InstagramPublisher extends AbstractPublisher
{
    private const API_VERSION = 'v21.0';
    private const BASE_URL    = 'https://graph.facebook.com/' . self::API_VERSION;
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

        if (!$token || !$igUserId) {
            return PublishResult::fail('Conta Instagram sem token ou ID configurado. Reconecte a conta.');
        }

        $caption    = $this->buildCaption($post);
        $mediaItems = $post->media->sortBy('order')->values();

        if ($mediaItems->isEmpty()) {
            return PublishResult::fail('Instagram requer pelo menos uma imagem ou vídeo para publicar.');
        }

        if ($mediaItems->count() === 1) {
            return $this->publishSingle($igUserId, $token, $caption, $mediaItems->first(), $post->type?->value);
        }

        return $this->publishCarousel($igUserId, $token, $caption, $mediaItems);
    }

    // ===== Publicação de mídia única =====

    private function publishSingle(string $igUserId, string $token, string $caption, PostMedia $media, ?string $postType): PublishResult
    {
        $isVideo  = $media->type === 'video';
        $isReel   = $postType === 'reel';

        $params = ['caption' => $caption, 'access_token' => $token];

        if ($isVideo || $isReel) {
            $params['media_type'] = 'REELS';
            $params['video_url']  = $this->mediaUrl($media);
        } else {
            $params['image_url'] = $this->mediaUrl($media);
        }

        $containerResponse = Http::post(self::BASE_URL . "/{$igUserId}/media", $params);

        if (!$containerResponse->successful()) {
            return PublishResult::fail('Erro ao criar container: ' . $this->apiError($containerResponse));
        }

        $creationId = $containerResponse->json('id');

        if ($isVideo || $isReel) {
            $waitResult = $this->waitForVideoProcessing($creationId, $token);
            if ($waitResult !== null) {
                return $waitResult; // erro
            }
        }

        return $this->publishContainer($igUserId, $token, $creationId);
    }

    // ===== Publicação de carrossel =====

    private function publishCarousel(string $igUserId, string $token, string $caption, Collection $mediaItems): PublishResult
    {
        $childIds = [];

        foreach ($mediaItems as $media) {
            $params = ['is_carousel_item' => 'true', 'access_token' => $token];

            if ($media->type === 'video') {
                $params['media_type'] = 'VIDEO';
                $params['video_url']  = $this->mediaUrl($media);
            } else {
                $params['image_url'] = $this->mediaUrl($media);
            }

            $response = Http::post(self::BASE_URL . "/{$igUserId}/media", $params);

            if (!$response->successful()) {
                return PublishResult::fail('Erro ao criar item do carrossel: ' . $this->apiError($response));
            }

            $childIds[] = $response->json('id');
        }

        $carouselResponse = Http::post(self::BASE_URL . "/{$igUserId}/media", [
            'media_type'   => 'CAROUSEL',
            'children'     => implode(',', $childIds),
            'caption'      => $caption,
            'access_token' => $token,
        ]);

        if (!$carouselResponse->successful()) {
            return PublishResult::fail('Erro ao criar carrossel: ' . $this->apiError($carouselResponse));
        }

        return $this->publishContainer($igUserId, $token, $carouselResponse->json('id'));
    }

    // ===== Publicar container já criado =====

    private function publishContainer(string $igUserId, string $token, string $creationId): PublishResult
    {
        $response = Http::post(self::BASE_URL . "/{$igUserId}/media_publish", [
            'creation_id'  => $creationId,
            'access_token' => $token,
        ]);

        if (!$response->successful()) {
            return PublishResult::fail('Erro ao publicar no Instagram: ' . $this->apiError($response));
        }

        $postId  = $response->json('id');
        $postUrl = "https://www.instagram.com/p/{$postId}/";

        Log::info("Instagram: publicado com sucesso — post_id={$postId}");

        return PublishResult::ok($postId, $postUrl);
    }

    // ===== Aguardar processamento de vídeo =====

    private function waitForVideoProcessing(string $creationId, string $token): ?PublishResult
    {
        $waited = 0;

        while ($waited < self::VIDEO_POLL_MAX_SECONDS) {
            sleep(self::VIDEO_POLL_INTERVAL);
            $waited += self::VIDEO_POLL_INTERVAL;

            $status = Http::get(self::BASE_URL . "/{$creationId}", [
                'fields'       => 'status_code',
                'access_token' => $token,
            ])->json('status_code');

            if ($status === 'FINISHED') {
                return null;
            }

            if ($status === 'ERROR') {
                return PublishResult::fail('Erro ao processar vídeo no Instagram.');
            }

            Log::info("Instagram: aguardando processamento de vídeo — status={$status} waited={$waited}s");
        }

        return PublishResult::fail("Timeout aguardando processamento de vídeo ({$waited}s).");
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
        // file_path é relativo: "posts/{id}/filename.jpg"
        return rtrim(config('app.url'), '/') . '/storage/' . $media->file_path;
    }

    private function apiError($response): string
    {
        return $response->json('error.message')
            ?? $response->json('error.error_user_msg')
            ?? $response->body();
    }
}

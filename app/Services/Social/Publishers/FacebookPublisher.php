<?php

namespace App\Services\Social\Publishers;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\SocialAccount;
use App\Models\SystemLog;
use App\Services\Social\PublishResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publisher para Facebook Pages via Meta Graph API.
 *
 * Inclui fallback automático: se a URL da mídia falhar,
 * faz upload direto do arquivo (multipart) para o Graph API.
 */
class FacebookPublisher extends AbstractPublisher
{
    private const API_VERSION = 'v21.0';
    private const BASE_URL    = 'https://graph.facebook.com/' . self::API_VERSION;

    protected function platformName(): string
    {
        return 'Facebook';
    }

    protected function doPublish(Post $post, SocialAccount $account): PublishResult
    {
        $token  = $account->getFreshToken() ?? $account->access_token;
        $pageId = $account->platform_user_id;

        SystemLog::info('social', 'fb.publish.start', "Facebook: iniciando publicação do post #{$post->id}", [
            'post_id'     => $post->id,
            'account_id'  => $account->id,
            'username'    => $account->username,
            'page_id'     => $pageId,
            'has_token'   => !empty($token),
            'media_count' => $post->media->count(),
        ]);

        if (!$token || !$pageId) {
            return $this->fail($post, 'Conta Facebook sem token ou Page ID. Reconecte a conta.');
        }

        $pageToken = $this->getPageToken($post, $pageId, $token);
        $useToken  = $pageToken ?? $token;

        $caption    = $this->buildCaption($post);
        $mediaItems = $post->media->sortBy('order')->values();

        if ($mediaItems->isEmpty()) {
            return $this->publishText($post, $pageId, $useToken, $caption);
        }

        $firstMedia = $mediaItems->first();

        if ($firstMedia->type === 'video') {
            return $this->publishVideo($post, $pageId, $useToken, $caption, $firstMedia);
        }

        if ($mediaItems->count() === 1) {
            return $this->publishSinglePhoto($post, $pageId, $useToken, $caption, $firstMedia);
        }

        return $this->publishMultiplePhotos($post, $pageId, $useToken, $caption, $mediaItems);
    }

    // ===== Texto simples =====

    private function publishText(Post $post, string $pageId, string $token, string $caption): PublishResult
    {
        SystemLog::info('social', 'fb.feed.post', "Facebook: publicando texto no feed", [
            'post_id' => $post->id,
            'page_id' => $pageId,
        ]);

        $response = Http::post(self::BASE_URL . "/{$pageId}/feed", [
            'message'      => $caption,
            'access_token' => $token,
        ]);

        SystemLog::info('social', 'fb.feed.response', "Facebook: resposta do feed", [
            'post_id' => $post->id,
            'status'  => $response->status(),
            'body'    => $response->json(),
        ]);

        if (!$response->successful()) {
            return $this->fail($post, 'Erro ao publicar no Facebook: ' . $this->apiError($response), $response->json());
        }

        $postId  = $response->json('id');
        $postUrl = "https://www.facebook.com/{$postId}";

        SystemLog::info('social', 'fb.publish.success', "Facebook: post #{$post->id} publicado", [
            'post_id'          => $post->id,
            'platform_post_id' => $postId,
        ]);

        return PublishResult::ok($postId, $postUrl);
    }

    // ===== Foto única =====

    private function publishSinglePhoto(Post $post, string $pageId, string $token, string $caption, PostMedia $media): PublishResult
    {
        $url = $this->mediaUrl($media);

        SystemLog::info('social', 'fb.photo.post', "Facebook: publicando foto", [
            'post_id'   => $post->id,
            'media_id'  => $media->id,
            'media_url' => $url,
        ]);

        // Tentar via URL primeiro
        $response = Http::post(self::BASE_URL . "/{$pageId}/photos", [
            'url'          => $url,
            'caption'      => $caption,
            'access_token' => $token,
        ]);

        // Fallback: upload direto do arquivo se URL falhou
        if (!$response->successful()) {
            $fallback = $this->uploadPhotoDirectly($post, $pageId, $token, $caption, $media, true);
            if ($fallback) {
                $response = $fallback;
            }
        }

        SystemLog::info('social', 'fb.photo.response', "Facebook: resposta foto", [
            'post_id' => $post->id,
            'status'  => $response->status(),
            'body'    => $response->json(),
        ]);

        if (!$response->successful()) {
            return $this->fail($post, 'Erro ao publicar foto no Facebook: ' . $this->apiError($response), $response->json());
        }

        $postId  = $response->json('post_id') ?? $response->json('id');
        $postUrl = "https://www.facebook.com/{$postId}";

        SystemLog::info('social', 'fb.publish.success', "Facebook: foto do post #{$post->id} publicada", [
            'post_id'          => $post->id,
            'platform_post_id' => $postId,
        ]);

        return PublishResult::ok($postId, $postUrl);
    }

    // ===== Múltiplas fotos =====

    private function publishMultiplePhotos(Post $post, string $pageId, string $token, string $caption, $mediaItems): PublishResult
    {
        $photoIds = [];

        foreach ($mediaItems as $index => $media) {
            $url = $this->mediaUrl($media);

            SystemLog::info('social', 'fb.multi.photo.upload', "Facebook: enviando foto #{$index}", [
                'post_id'   => $post->id,
                'media_id'  => $media->id,
                'media_url' => $url,
            ]);

            // Tentar via URL primeiro
            $response = Http::post(self::BASE_URL . "/{$pageId}/photos", [
                'url'          => $url,
                'published'    => false,
                'access_token' => $token,
            ]);

            // Fallback: upload direto
            if (!$response->successful()) {
                $fallback = $this->uploadPhotoDirectly($post, $pageId, $token, null, $media, false);
                if ($fallback) {
                    $response = $fallback;
                }
            }

            SystemLog::info('social', 'fb.multi.photo.response', "Facebook: resposta upload foto #{$index}", [
                'post_id' => $post->id,
                'status'  => $response->status(),
                'body'    => $response->json(),
            ]);

            if (!$response->successful()) {
                return $this->fail($post, "Erro ao enviar foto #{$index}: " . $this->apiError($response), $response->json());
            }

            $photoIds[] = ['media_fbid' => $response->json('id')];
        }

        SystemLog::info('social', 'fb.multi.feed.post', "Facebook: publicando feed com múltiplas fotos", [
            'post_id'   => $post->id,
            'photo_ids' => $photoIds,
        ]);

        $response = Http::post(self::BASE_URL . "/{$pageId}/feed", [
            'message'        => $caption,
            'attached_media' => json_encode($photoIds),
            'access_token'   => $token,
        ]);

        SystemLog::info('social', 'fb.multi.feed.response', "Facebook: resposta feed múltiplas fotos", [
            'post_id' => $post->id,
            'status'  => $response->status(),
            'body'    => $response->json(),
        ]);

        if (!$response->successful()) {
            return $this->fail($post, 'Erro ao publicar álbum no Facebook: ' . $this->apiError($response), $response->json());
        }

        $postId  = $response->json('id');
        $postUrl = "https://www.facebook.com/{$postId}";

        SystemLog::info('social', 'fb.publish.success', "Facebook: álbum do post #{$post->id} publicado", [
            'post_id'          => $post->id,
            'platform_post_id' => $postId,
        ]);

        return PublishResult::ok($postId, $postUrl);
    }

    // ===== Vídeo =====

    private function publishVideo(Post $post, string $pageId, string $token, string $caption, PostMedia $media): PublishResult
    {
        $url = $this->mediaUrl($media);

        SystemLog::info('social', 'fb.video.post', "Facebook: publicando vídeo", [
            'post_id'   => $post->id,
            'media_id'  => $media->id,
            'video_url' => $url,
        ]);

        $response = Http::post(self::BASE_URL . "/{$pageId}/videos", [
            'file_url'     => $url,
            'description'  => $caption,
            'access_token' => $token,
        ]);

        SystemLog::info('social', 'fb.video.response', "Facebook: resposta vídeo", [
            'post_id' => $post->id,
            'status'  => $response->status(),
            'body'    => $response->json(),
        ]);

        if (!$response->successful()) {
            return $this->fail($post, 'Erro ao publicar vídeo no Facebook: ' . $this->apiError($response), $response->json());
        }

        $videoId = $response->json('id');
        $postUrl = "https://www.facebook.com/{$pageId}/videos/{$videoId}";

        SystemLog::info('social', 'fb.publish.success', "Facebook: vídeo do post #{$post->id} publicado", [
            'post_id'          => $post->id,
            'platform_post_id' => $videoId,
        ]);

        return PublishResult::ok($videoId, $postUrl);
    }

    // ===== Upload direto (fallback) =====

    /**
     * Faz upload direto do arquivo via multipart/form-data quando a URL falha.
     */
    private function uploadPhotoDirectly(Post $post, string $pageId, string $token, ?string $caption, PostMedia $media, bool $published): mixed
    {
        $localPath = storage_path('app/public/' . $media->file_path);

        if (!file_exists($localPath)) {
            SystemLog::warning('social', 'fb.upload.file_missing', "Facebook: arquivo local não encontrado para upload direto", [
                'post_id'    => $post->id,
                'file_path'  => $media->file_path,
                'local_path' => $localPath,
            ]);
            return null;
        }

        $mimeType = mime_content_type($localPath) ?: 'image/jpeg';

        SystemLog::info('social', 'fb.upload.direct', "Facebook: fazendo upload direto do arquivo", [
            'post_id'   => $post->id,
            'media_id'  => $media->id,
            'file_size' => filesize($localPath),
            'mime_type' => $mimeType,
        ]);

        $params = [
            'published'    => $published ? 'true' : 'false',
            'access_token' => $token,
        ];

        if ($caption) {
            $params['caption'] = $caption;
        }

        $response = Http::attach(
            'source',
            file_get_contents($localPath),
            basename($localPath),
            ['Content-Type' => $mimeType]
        )->post(self::BASE_URL . "/{$pageId}/photos", $params);

        SystemLog::info('social', 'fb.upload.direct.response', "Facebook: resposta upload direto", [
            'post_id' => $post->id,
            'status'  => $response->status(),
            'body'    => $response->json(),
        ]);

        return $response->successful() ? $response : null;
    }

    // ===== Helpers =====

    private function getPageToken(Post $post, string $pageId, string $userToken): ?string
    {
        try {
            $response = Http::get(self::BASE_URL . "/{$pageId}", [
                'fields'       => 'access_token,name',
                'access_token' => $userToken,
            ]);

            SystemLog::info('social', 'fb.page_token.response', "Facebook: resposta Page Token", [
                'post_id' => $post->id,
                'page_id' => $pageId,
                'status'  => $response->status(),
                'has_token' => !empty($response->json('access_token')),
                'page_name' => $response->json('name'),
            ]);

            $pageToken = $response->json('access_token');

            if ($pageToken) {
                return $pageToken;
            }
        } catch (\Throwable $e) {
            SystemLog::warning('social', 'fb.page_token.error', "Facebook: erro ao obter Page Token", [
                'post_id' => $post->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return null;
    }

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
        SystemLog::error('social', 'fb.publish.error', "Facebook: falha ao publicar post #{$post->id}", [
            'post_id'      => $post->id,
            'error'        => $message,
            'api_response' => $apiResponse,
        ]);

        return PublishResult::fail($message);
    }
}

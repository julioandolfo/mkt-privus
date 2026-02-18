<?php

namespace App\Services\Social\Publishers;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\SocialAccount;
use App\Services\Social\PublishResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publisher para Facebook Pages via Meta Graph API.
 *
 * Requer que a conta conectada seja uma Página do Facebook (não perfil pessoal).
 * O platform_user_id deve ser o Page ID.
 *
 * Fluxo:
 *  - Texto simples: POST /{page-id}/feed
 *  - Com imagem(ns): POST /{page-id}/photos (uma por vez) + link no feed
 *  - Com vídeo: POST /{page-id}/videos
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

        if (!$token || !$pageId) {
            return PublishResult::fail('Conta Facebook sem token ou Page ID. Reconecte a conta.');
        }

        // Tentar obter Page Access Token (necessário para publicar como página)
        $pageToken = $this->getPageToken($pageId, $token);
        $useToken  = $pageToken ?? $token;

        $caption    = $this->buildCaption($post);
        $mediaItems = $post->media->sortBy('order')->values();

        // Sem mídia — post de texto
        if ($mediaItems->isEmpty()) {
            return $this->publishText($pageId, $useToken, $caption);
        }

        $firstMedia = $mediaItems->first();

        // Vídeo
        if ($firstMedia->type === 'video') {
            return $this->publishVideo($pageId, $useToken, $caption, $firstMedia);
        }

        // Uma ou mais imagens
        if ($mediaItems->count() === 1) {
            return $this->publishSinglePhoto($pageId, $useToken, $caption, $firstMedia);
        }

        return $this->publishMultiplePhotos($pageId, $useToken, $caption, $mediaItems);
    }

    // ===== Texto simples =====

    private function publishText(string $pageId, string $token, string $caption): PublishResult
    {
        $response = Http::post(self::BASE_URL . "/{$pageId}/feed", [
            'message'      => $caption,
            'access_token' => $token,
        ]);

        if (!$response->successful()) {
            return PublishResult::fail('Erro ao publicar no Facebook: ' . $this->apiError($response));
        }

        $postId  = $response->json('id');
        $postUrl = "https://www.facebook.com/{$postId}";

        return PublishResult::ok($postId, $postUrl);
    }

    // ===== Foto única =====

    private function publishSinglePhoto(string $pageId, string $token, string $caption, PostMedia $media): PublishResult
    {
        $response = Http::post(self::BASE_URL . "/{$pageId}/photos", [
            'url'          => $this->mediaUrl($media),
            'caption'      => $caption,
            'access_token' => $token,
        ]);

        if (!$response->successful()) {
            return PublishResult::fail('Erro ao publicar foto no Facebook: ' . $this->apiError($response));
        }

        $postId  = $response->json('post_id') ?? $response->json('id');
        $postUrl = "https://www.facebook.com/{$postId}";

        return PublishResult::ok($postId, $postUrl);
    }

    // ===== Múltiplas fotos (álbum) =====

    private function publishMultiplePhotos(string $pageId, string $token, string $caption, $mediaItems): PublishResult
    {
        // Publicar cada foto sem story (unpublished) e coletar IDs
        $photoIds = [];

        foreach ($mediaItems as $media) {
            $response = Http::post(self::BASE_URL . "/{$pageId}/photos", [
                'url'           => $this->mediaUrl($media),
                'published'     => false,
                'access_token'  => $token,
            ]);

            if (!$response->successful()) {
                return PublishResult::fail('Erro ao enviar foto para Facebook: ' . $this->apiError($response));
            }

            $photoIds[] = ['media_fbid' => $response->json('id')];
        }

        // Publicar post com todas as fotos
        $response = Http::post(self::BASE_URL . "/{$pageId}/feed", [
            'message'          => $caption,
            'attached_media'   => json_encode($photoIds),
            'access_token'     => $token,
        ]);

        if (!$response->successful()) {
            return PublishResult::fail('Erro ao publicar álbum no Facebook: ' . $this->apiError($response));
        }

        $postId  = $response->json('id');
        $postUrl = "https://www.facebook.com/{$postId}";

        return PublishResult::ok($postId, $postUrl);
    }

    // ===== Vídeo =====

    private function publishVideo(string $pageId, string $token, string $caption, PostMedia $media): PublishResult
    {
        $response = Http::post(self::BASE_URL . "/{$pageId}/videos", [
            'file_url'     => $this->mediaUrl($media),
            'description'  => $caption,
            'access_token' => $token,
        ]);

        if (!$response->successful()) {
            return PublishResult::fail('Erro ao publicar vídeo no Facebook: ' . $this->apiError($response));
        }

        $videoId = $response->json('id');
        $postUrl = "https://www.facebook.com/{$pageId}/videos/{$videoId}";

        return PublishResult::ok($videoId, $postUrl);
    }

    // ===== Helpers =====

    /**
     * Tenta obter o Page Access Token a partir do User Token.
     * Retorna null se não conseguir (continuará usando o token do usuário).
     */
    private function getPageToken(string $pageId, string $userToken): ?string
    {
        try {
            $response = Http::get(self::BASE_URL . "/{$pageId}", [
                'fields'       => 'access_token',
                'access_token' => $userToken,
            ]);

            $pageToken = $response->json('access_token');

            if ($pageToken) {
                Log::info("Facebook: Page Access Token obtido para Page {$pageId}");
                return $pageToken;
            }
        } catch (\Throwable $e) {
            Log::warning("Facebook: Não foi possível obter Page Token: {$e->getMessage()}");
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
            ?? $response->body();
    }
}

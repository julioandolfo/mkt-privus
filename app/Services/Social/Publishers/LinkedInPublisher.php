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
 * Publisher para LinkedIn via API v2 (UGC Posts).
 */
class LinkedInPublisher extends AbstractPublisher
{
    private const BASE_URL = 'https://api.linkedin.com/v2';

    protected function platformName(): string
    {
        return 'LinkedIn';
    }

    protected function doPublish(Post $post, SocialAccount $account): PublishResult
    {
        $token = $account->getFreshToken() ?? $account->access_token;

        SystemLog::info('social', 'li.publish.start', "LinkedIn: iniciando publicação do post #{$post->id}", [
            'post_id'     => $post->id,
            'account_id'  => $account->id,
            'username'    => $account->username,
            'platform_id' => $account->platform_user_id,
            'metadata'    => $account->metadata,
            'has_token'   => !empty($token),
            'media_count' => $post->media->count(),
        ]);

        if (!$token) {
            return $this->fail($post, 'Conta LinkedIn sem token. Reconecte a conta.');
        }

        $authorUrn  = $this->resolveAuthorUrn($account);
        $caption    = $this->buildCaption($post);
        $mediaItems = $post->media->sortBy('order')->values();

        SystemLog::info('social', 'li.publish.author', "LinkedIn: autor resolvido", [
            'post_id'    => $post->id,
            'author_urn' => $authorUrn,
        ]);

        if ($mediaItems->isEmpty()) {
            return $this->publishText($post, $token, $authorUrn, $caption);
        }

        $video = $mediaItems->where('type', 'video')->first();
        if ($video) {
            return $this->publishWithVideo($post, $token, $authorUrn, $caption, $video);
        }

        $images = $mediaItems->where('type', 'image')->values();
        return $this->publishWithImage($post, $token, $authorUrn, $caption, $images->first());
    }

    // ===== Texto simples =====

    private function publishText(Post $post, string $token, string $authorUrn, string $caption): PublishResult
    {
        $body = [
            'author'          => $authorUrn,
            'lifecycleState'  => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary'    => ['text' => $caption],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        SystemLog::info('social', 'li.text.post', "LinkedIn: publicando texto", [
            'post_id'    => $post->id,
            'author_urn' => $authorUrn,
            'body'       => $body,
        ]);

        $response = Http::withToken($token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post(self::BASE_URL . '/ugcPosts', $body);

        SystemLog::info('social', 'li.text.response', "LinkedIn: resposta texto", [
            'post_id' => $post->id,
            'status'  => $response->status(),
            'headers' => $response->headers(),
            'body'    => $response->json() ?? $response->body(),
        ]);

        if (!$response->successful()) {
            return $this->fail($post, 'Erro ao publicar no LinkedIn: ' . $this->apiError($response), $response->json());
        }

        $postId  = $response->json('id') ?? $response->header('X-RestLi-Id');
        $postUrl = "https://www.linkedin.com/feed/update/{$postId}";

        SystemLog::info('social', 'li.publish.success', "LinkedIn: post #{$post->id} publicado", [
            'post_id'          => $post->id,
            'platform_post_id' => $postId,
        ]);

        return PublishResult::ok($postId ?? 'li_text', $postUrl);
    }

    // ===== Com imagem =====

    private function publishWithImage(Post $post, string $token, string $authorUrn, string $caption, PostMedia $media): PublishResult
    {
        // Passo 1: Registrar upload
        $registerBody = [
            'registerUploadRequest' => [
                'recipes'              => ['urn:li:digitalmediaRecipe:feedshare-image'],
                'owner'                => $authorUrn,
                'serviceRelationships' => [[
                    'relationshipType' => 'OWNER',
                    'identifier'       => 'urn:li:userGeneratedContent',
                ]],
            ],
        ];

        SystemLog::info('social', 'li.image.register', "LinkedIn: registrando upload de imagem", [
            'post_id'   => $post->id,
            'media_id'  => $media->id,
            'file_path' => $media->file_path,
            'body'      => $registerBody,
        ]);

        $registerResponse = Http::withToken($token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post(self::BASE_URL . '/assets?action=registerUpload', $registerBody);

        SystemLog::info('social', 'li.image.register.response', "LinkedIn: resposta registro upload", [
            'post_id' => $post->id,
            'status'  => $registerResponse->status(),
            'body'    => $registerResponse->json(),
        ]);

        if (!$registerResponse->successful()) {
            return $this->fail($post, 'Erro ao registrar upload no LinkedIn: ' . $this->apiError($registerResponse), $registerResponse->json());
        }

        $uploadUrl = $registerResponse->json('value.uploadMechanism.com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest.uploadUrl');
        $assetUrn  = $registerResponse->json('value.asset');

        SystemLog::info('social', 'li.image.upload_url', "LinkedIn: URL de upload obtida", [
            'post_id'    => $post->id,
            'upload_url' => $uploadUrl ? substr($uploadUrl, 0, 100) . '...' : null,
            'asset_urn'  => $assetUrn,
        ]);

        if (!$uploadUrl || !$assetUrn) {
            return $this->fail($post, 'LinkedIn retornou URL de upload ou asset URN inválido.', $registerResponse->json());
        }

        // Passo 2: Upload do binário
        $filePath = storage_path('app/public/' . $media->file_path);

        SystemLog::info('social', 'li.image.binary.upload', "LinkedIn: enviando binário da imagem", [
            'post_id'     => $post->id,
            'file_path'   => $filePath,
            'file_exists' => file_exists($filePath),
            'file_size'   => file_exists($filePath) ? filesize($filePath) : 0,
            'mime_type'   => $media->mime_type,
        ]);

        if (!file_exists($filePath)) {
            return $this->fail($post, "Arquivo de mídia não encontrado: {$media->file_path}");
        }

        $uploadResponse = Http::withToken($token)
            ->withHeaders([
                'Content-Type'              => $media->mime_type ?? 'image/jpeg',
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->withBody(file_get_contents($filePath), $media->mime_type ?? 'image/jpeg')
            ->put($uploadUrl);

        SystemLog::info('social', 'li.image.binary.response', "LinkedIn: resposta upload binário", [
            'post_id' => $post->id,
            'status'  => $uploadResponse->status(),
            'body'    => substr($uploadResponse->body(), 0, 200),
        ]);

        // Status 201 ou 200 são válidos para upload
        if (!in_array($uploadResponse->status(), [200, 201])) {
            SystemLog::warning('social', 'li.image.binary.warning', "LinkedIn: upload retornou status inesperado (continuando)", [
                'post_id' => $post->id,
                'status'  => $uploadResponse->status(),
            ]);
        }

        // Passo 3: Criar post com asset
        $postBody = [
            'author'          => $authorUrn,
            'lifecycleState'  => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary'    => ['text' => $caption],
                    'shareMediaCategory' => 'IMAGE',
                    'media'              => [[
                        'status'      => 'READY',
                        'description' => ['text' => mb_substr($caption, 0, 200)],
                        'media'       => $assetUrn,
                    ]],
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        SystemLog::info('social', 'li.image.post', "LinkedIn: criando post com imagem", [
            'post_id'   => $post->id,
            'asset_urn' => $assetUrn,
            'body'      => $postBody,
        ]);

        $response = Http::withToken($token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post(self::BASE_URL . '/ugcPosts', $postBody);

        SystemLog::info('social', 'li.image.post.response', "LinkedIn: resposta post com imagem", [
            'post_id' => $post->id,
            'status'  => $response->status(),
            'headers' => $response->headers(),
            'body'    => $response->json() ?? $response->body(),
        ]);

        if (!$response->successful()) {
            return $this->fail($post, 'Erro ao publicar imagem no LinkedIn: ' . $this->apiError($response), $response->json());
        }

        $postId  = $response->json('id') ?? $response->header('X-RestLi-Id');
        $postUrl = "https://www.linkedin.com/feed/update/{$postId}";

        SystemLog::info('social', 'li.publish.success', "LinkedIn: imagem do post #{$post->id} publicada", [
            'post_id'          => $post->id,
            'platform_post_id' => $postId,
        ]);

        return PublishResult::ok($postId ?? 'li_image', $postUrl);
    }

    // ===== Com vídeo =====

    private function publishWithVideo(Post $post, string $token, string $authorUrn, string $caption, PostMedia $media): PublishResult
    {
        SystemLog::warning('social', 'li.video.fallback', "LinkedIn: publicação de vídeo via API v2 não suportada — publicando texto", [
            'post_id'  => $post->id,
            'media_id' => $media->id,
        ]);

        return $this->publishText($post, $token, $authorUrn, $caption . "\n\n[Vídeo disponível em breve]");
    }

    // ===== Helpers =====

    private function resolveAuthorUrn(SocialAccount $account): string
    {
        $metadata = $account->metadata ?? [];
        $type     = $metadata['type'] ?? $metadata['account_type'] ?? 'person';
        $id       = $account->platform_user_id;

        if (str_starts_with((string) $id, 'urn:li:')) {
            return $id;
        }

        if (in_array($type, ['organization', 'company', 'page'])) {
            return "urn:li:organization:{$id}";
        }

        return "urn:li:person:{$id}";
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

    private function apiError($response): string
    {
        return $response->json('message')
            ?? $response->json('error.message')
            ?? substr($response->body(), 0, 300);
    }

    private function fail(Post $post, string $message, ?array $apiResponse = null): PublishResult
    {
        SystemLog::error('social', 'li.publish.error', "LinkedIn: falha ao publicar post #{$post->id}", [
            'post_id'      => $post->id,
            'error'        => $message,
            'api_response' => $apiResponse,
        ]);

        return PublishResult::fail($message);
    }
}

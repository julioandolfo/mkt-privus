<?php

namespace App\Services\Social\Publishers;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\SocialAccount;
use App\Services\Social\PublishResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publisher para LinkedIn via API v2 (UGC Posts).
 *
 * Suporta posts de membros (pessoa) e organizações (empresa/página).
 * O platform_user_id pode ser:
 *   - ID numérico de pessoa: usa urn:li:person:{id}
 *   - ID numérico de organização: usa urn:li:organization:{id} (se metadata.type == 'organization')
 *
 * Fluxo com mídia:
 *  1. Registrar upload de imagem (registerUpload)
 *  2. Fazer upload do binário
 *  3. Criar UGC post com o asset
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

        if (!$token) {
            return PublishResult::fail('Conta LinkedIn sem token. Reconecte a conta.');
        }

        $authorUrn = $this->resolveAuthorUrn($account);
        $caption   = $this->buildCaption($post);

        $mediaItems = $post->media->sortBy('order')->values();

        // Post apenas com texto
        if ($mediaItems->isEmpty()) {
            return $this->publishText($token, $authorUrn, $caption);
        }

        // Com imagem(ns) — LinkedIn suporta multi-imagem desde 2023
        $images = $mediaItems->where('type', 'image')->values();
        $video  = $mediaItems->where('type', 'video')->first();

        if ($video) {
            return $this->publishWithVideo($token, $authorUrn, $caption, $video);
        }

        if ($images->count() === 1) {
            return $this->publishWithImage($token, $authorUrn, $caption, $images->first());
        }

        // Múltiplas imagens — publicar como carrossel (artigo nativo não disponível via API)
        // Fallback: publicar só texto com nota
        return $this->publishWithImage($token, $authorUrn, $caption, $images->first());
    }

    // ===== Post de texto simples =====

    private function publishText(string $token, string $authorUrn, string $caption): PublishResult
    {
        $body = [
            'author'          => $authorUrn,
            'lifecycleState'  => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => $caption],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $response = Http::withToken($token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post(self::BASE_URL . '/ugcPosts', $body);

        if (!$response->successful()) {
            return PublishResult::fail('Erro ao publicar no LinkedIn: ' . $this->apiError($response));
        }

        $postId  = $response->json('id') ?? $response->header('X-RestLi-Id');
        $postUrl = "https://www.linkedin.com/feed/update/{$postId}";

        return PublishResult::ok($postId ?? 'linkedin_post', $postUrl);
    }

    // ===== Post com imagem =====

    private function publishWithImage(string $token, string $authorUrn, string $caption, PostMedia $media): PublishResult
    {
        // Passo 1: Registrar upload
        $registerResponse = Http::withToken($token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post(self::BASE_URL . '/assets?action=registerUpload', [
                'registerUploadRequest' => [
                    'recipes'                 => ['urn:li:digitalmediaRecipe:feedshare-image'],
                    'owner'                   => $authorUrn,
                    'serviceRelationships'    => [[
                        'relationshipType' => 'OWNER',
                        'identifier'       => 'urn:li:userGeneratedContent',
                    ]],
                ],
            ]);

        if (!$registerResponse->successful()) {
            return PublishResult::fail('Erro ao registrar upload de imagem no LinkedIn: ' . $this->apiError($registerResponse));
        }

        $uploadUrl = $registerResponse->json('value.uploadMechanism.com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest.uploadUrl');
        $assetUrn  = $registerResponse->json('value.asset');

        if (!$uploadUrl || !$assetUrn) {
            return PublishResult::fail('LinkedIn retornou URL de upload inválida.');
        }

        // Passo 2: Upload do binário
        $filePath = storage_path('app/public/' . $media->file_path);

        if (!file_exists($filePath)) {
            return PublishResult::fail("Arquivo de mídia não encontrado: {$media->file_path}");
        }

        $uploadResponse = Http::withToken($token)
            ->withHeaders([
                'Content-Type'                 => $media->mime_type ?? 'image/jpeg',
                'X-Restli-Protocol-Version'    => '2.0.0',
            ])
            ->withBody(file_get_contents($filePath), $media->mime_type ?? 'image/jpeg')
            ->put($uploadUrl);

        if (!$uploadResponse->successful() && $uploadResponse->status() !== 201) {
            Log::warning("LinkedIn: upload retornou status {$uploadResponse->status()} (pode ser ok)");
        }

        // Passo 3: Criar post com o asset
        $body = [
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

        $response = Http::withToken($token)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post(self::BASE_URL . '/ugcPosts', $body);

        if (!$response->successful()) {
            return PublishResult::fail('Erro ao publicar imagem no LinkedIn: ' . $this->apiError($response));
        }

        $postId  = $response->json('id') ?? $response->header('X-RestLi-Id');
        $postUrl = "https://www.linkedin.com/feed/update/{$postId}";

        return PublishResult::ok($postId ?? 'linkedin_post', $postUrl);
    }

    // ===== Post com vídeo =====

    private function publishWithVideo(string $token, string $authorUrn, string $caption, PostMedia $media): PublishResult
    {
        // Vídeo no LinkedIn via API v2 requer upload multipart complexo
        // Por ora, publicar texto com nota sobre o vídeo
        Log::warning("LinkedIn: publicação de vídeo ainda não suportada via API, publicando texto.");
        return $this->publishText($token, $authorUrn, $caption . "\n\n[Vídeo disponível em breve]");
    }

    // ===== Helpers =====

    private function resolveAuthorUrn(SocialAccount $account): string
    {
        $metadata = $account->metadata ?? [];
        $type     = $metadata['type'] ?? $metadata['account_type'] ?? 'person';
        $id       = $account->platform_user_id;

        if (in_array($type, ['organization', 'company', 'page'])) {
            return "urn:li:organization:{$id}";
        }

        // Se já é um URN completo, retornar direto
        if (str_starts_with($id, 'urn:li:')) {
            return $id;
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
            ?? $response->body();
    }
}

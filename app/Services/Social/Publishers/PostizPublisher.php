<?php

namespace App\Services\Social\Publishers;

use App\Enums\SocialPlatform;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\SocialAccount;
use App\Models\SystemLog;
use App\Services\Social\Postiz\PostizGateway;
use App\Services\Social\PublishResult;
use RuntimeException;
use Throwable;

/**
 * Publisher que delega a publicação para a API pública do Postiz.
 *
 * Usado para LinkedIn (perfil), LinkedIn Page, TikTok e Google My Business.
 * Instagram, Facebook e YouTube seguem com publishers diretos próprios.
 */
class PostizPublisher extends AbstractPublisher
{
    public function __construct(
        private readonly SocialPlatform $platform,
        private readonly ?PostizGateway $gateway = null,
    ) {}

    protected function platformName(): string
    {
        return 'Postiz/' . $this->platform->label();
    }

    protected function doPublish(Post $post, SocialAccount $account): PublishResult
    {
        if (!$account->postiz_integration_id) {
            return PublishResult::fail(
                "Conta @{$account->username} não está vinculada a uma integration Postiz. Reconecte a conta."
            );
        }

        $postizType = $this->platform->postizIdentifier();
        if (!$postizType) {
            return PublishResult::fail("Plataforma {$this->platform->label()} não suporta publicação via Postiz.");
        }

        $gateway = $this->gateway ?? PostizGateway::fromConfig();

        try {
            $media = $this->uploadMedia($gateway, $post);
            $payload = $this->buildPayload($account, $post, $postizType, $media);

            SystemLog::info('social', 'postiz.publish.start', "Postiz: publicando post #{$post->id} em {$postizType}", [
                'post_id' => $post->id,
                'account_id' => $account->id,
                'integration_id' => $account->postiz_integration_id,
                'platform' => $this->platform->value,
                'media_count' => count($media),
            ]);

            $result = $gateway->createPost($payload);

            $first = $result[0] ?? null;
            $postId = $first['postId'] ?? null;

            if (!$postId) {
                throw new RuntimeException('Postiz não retornou postId na resposta.');
            }

            SystemLog::info('social', 'postiz.publish.success', "Postiz: post #{$post->id} publicado", [
                'post_id' => $post->id,
                'postiz_post_id' => $postId,
                'platform' => $this->platform->value,
            ]);

            return PublishResult::ok($postId);
        } catch (Throwable $e) {
            SystemLog::error('social', 'postiz.publish.error', "Postiz: falha ao publicar post #{$post->id}: {$e->getMessage()}", [
                'post_id' => $post->id,
                'platform' => $this->platform->value,
                'integration_id' => $account->postiz_integration_id,
            ]);
            return PublishResult::fail($e->getMessage());
        }
    }

    /**
     * Envia cada mídia do post ao Postiz via upload-from-url e devolve
     * a lista de referências no formato esperado pelo payload do /posts.
     *
     * @return array<int, array{id:string,path:string}>
     */
    private function uploadMedia(PostizGateway $gateway, Post $post): array
    {
        $uploaded = [];

        foreach ($post->media->sortBy('order') as $media) {
            /** @var PostMedia $media */
            $publicUrl = $this->mediaPublicUrl($media);
            $ref = $gateway->uploadFromUrl($publicUrl);
            $uploaded[] = $ref;
        }

        return $uploaded;
    }

    private function mediaPublicUrl(PostMedia $media): string
    {
        return rtrim(config('app.url'), '/') . '/storage/' . ltrim($media->file_path, '/');
    }

    /**
     * Monta o corpo da requisição POST /posts conforme o schema do Postiz.
     */
    private function buildPayload(SocialAccount $account, Post $post, string $postizType, array $media): array
    {
        $valueBlock = [
            'content' => $this->buildContent($post),
        ];

        if (!empty($media)) {
            $valueBlock['image'] = $media;
        }

        return [
            'type' => 'now',
            'date' => now()->toIso8601String(),
            'shortLink' => false,
            'tags' => [],
            'posts' => [[
                'integration' => ['id' => $account->postiz_integration_id],
                'value' => [$valueBlock],
                'settings' => $this->buildPlatformSettings($postizType, $post),
            ]],
        ];
    }

    private function buildContent(Post $post): string
    {
        $text = trim((string) $post->caption);
        $hashtags = $post->hashtags ?? [];

        if (!empty($hashtags)) {
            $tagLine = collect($hashtags)
                ->map(fn($t) => str_starts_with($t, '#') ? $t : '#' . $t)
                ->implode(' ');
            $text = trim($text . "\n\n" . $tagLine);
        }

        return $text;
    }

    /**
     * Settings específicas por plataforma exigidas pelo Postiz.
     *
     * Valores defensivos (defaults razoáveis). Futuramente podem vir do $post->metadata
     * caso o UI exponha opções avançadas por plataforma.
     */
    private function buildPlatformSettings(string $postizType, Post $post): array
    {
        $base = ['__type' => $postizType];

        return match ($postizType) {
            'linkedin' => $base,
            'linkedin-page' => $base + [
                'post_as_images_carousel' => (bool) ($post->metadata['linkedin']['carousel'] ?? false),
                'carousel_name' => $post->metadata['linkedin']['carousel_name'] ?? '',
            ],
            'tiktok' => $base + [
                'privacy_level' => $post->metadata['tiktok']['privacy_level'] ?? 'PUBLIC_TO_EVERYONE',
                'duet' => (bool) ($post->metadata['tiktok']['duet'] ?? true),
                'stitch' => (bool) ($post->metadata['tiktok']['stitch'] ?? true),
                'comment' => (bool) ($post->metadata['tiktok']['comment'] ?? true),
                'brand_content_toggle' => (bool) ($post->metadata['tiktok']['brand_content_toggle'] ?? false),
                'brand_organic_toggle' => (bool) ($post->metadata['tiktok']['brand_organic_toggle'] ?? false),
                'content_posting_method' => $post->metadata['tiktok']['content_posting_method'] ?? 'DIRECT_POST',
            ],
            'googlebusiness' => $base,
            default => $base,
        };
    }
}

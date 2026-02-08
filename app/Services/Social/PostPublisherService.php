<?php

namespace App\Services\Social;

use App\Enums\SocialPlatform;
use App\Models\Post;
use App\Models\PostSchedule;
use App\Models\SocialAccount;
use App\Services\Social\Publishers\AbstractPublisher;
use App\Services\Social\Publishers\FacebookPublisher;
use App\Services\Social\Publishers\InstagramPublisher;
use App\Services\Social\Publishers\LinkedInPublisher;
use App\Services\Social\Publishers\PinterestPublisher;
use App\Services\Social\Publishers\TikTokPublisher;
use App\Services\Social\Publishers\YouTubePublisher;
use Illuminate\Support\Facades\Log;

/**
 * Servico que orquestra a publicacao de posts nas redes sociais.
 * Seleciona o publisher correto com base na plataforma e executa a publicacao.
 */
class PostPublisherService
{
    /**
     * Publica um schedule especifico na plataforma correspondente.
     */
    public function publish(PostSchedule $schedule): PublishResult
    {
        $post = $schedule->post;
        $account = $schedule->socialAccount;

        if (!$post) {
            return PublishResult::fail('Post não encontrado.');
        }

        if (!$account) {
            // Publicacao sem conta vinculada - simular publicacao direta
            Log::info("Autopilot: Publicando post #{$post->id} sem conta vinculada na plataforma {$schedule->platform->label()}");
            return $this->publishWithoutAccount($post, $schedule->platform);
        }

        $publisher = $this->resolvePublisher($schedule->platform);

        if (!$publisher) {
            return PublishResult::fail("Publisher não disponível para {$schedule->platform->label()}.");
        }

        return $publisher->publish($post, $account);
    }

    /**
     * Publica sem conta social vinculada (apenas simula com log).
     * Util quando o usuario cria schedule sem ter conta conectada.
     */
    private function publishWithoutAccount(Post $post, SocialPlatform $platform): PublishResult
    {
        Log::info("Autopilot [{$platform->label()}]: Publicação simulada (sem conta)", [
            'post_id' => $post->id,
            'caption_preview' => mb_substr($post->caption, 0, 50),
        ]);

        usleep(300000); // Simular latencia

        $fakeId = strtolower($platform->value) . '_sim_' . uniqid();

        return PublishResult::ok($fakeId);
    }

    /**
     * Resolve o publisher correto para a plataforma.
     */
    private function resolvePublisher(SocialPlatform $platform): ?AbstractPublisher
    {
        return match ($platform) {
            SocialPlatform::Instagram => new InstagramPublisher(),
            SocialPlatform::Facebook => new FacebookPublisher(),
            SocialPlatform::LinkedIn => new LinkedInPublisher(),
            SocialPlatform::TikTok => new TikTokPublisher(),
            SocialPlatform::YouTube => new YouTubePublisher(),
            SocialPlatform::Pinterest => new PinterestPublisher(),
        };
    }
}

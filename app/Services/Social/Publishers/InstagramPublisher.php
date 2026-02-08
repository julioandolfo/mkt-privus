<?php

namespace App\Services\Social\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\PublishResult;
use Illuminate\Support\Str;

/**
 * Publisher para Instagram.
 * TODO: Integrar com Meta Graph API quando APIs forem configuradas.
 */
class InstagramPublisher extends AbstractPublisher
{
    protected function platformName(): string
    {
        return 'Instagram';
    }

    protected function doPublish(Post $post, SocialAccount $account): PublishResult
    {
        // Simulacao - sera substituido pela integracao com Meta Graph API
        // POST https://graph.facebook.com/v18.0/{ig-user-id}/media
        // POST https://graph.facebook.com/v18.0/{ig-user-id}/media_publish

        usleep(500000); // Simular latencia de API (500ms)

        $fakePostId = 'ig_' . Str::random(15);
        $fakeUrl = "https://www.instagram.com/p/{$fakePostId}/";

        return PublishResult::ok($fakePostId, $fakeUrl);
    }
}

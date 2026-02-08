<?php

namespace App\Services\Social\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\PublishResult;
use Illuminate\Support\Str;

/**
 * Publisher para Facebook.
 * TODO: Integrar com Meta Graph API quando APIs forem configuradas.
 */
class FacebookPublisher extends AbstractPublisher
{
    protected function platformName(): string
    {
        return 'Facebook';
    }

    protected function doPublish(Post $post, SocialAccount $account): PublishResult
    {
        // Simulacao - sera substituido pela integracao com Meta Graph API
        // POST https://graph.facebook.com/v18.0/{page-id}/feed

        usleep(500000);

        $fakePostId = 'fb_' . Str::random(15);
        $fakeUrl = "https://www.facebook.com/{$account->platform_user_id}/posts/{$fakePostId}";

        return PublishResult::ok($fakePostId, $fakeUrl);
    }
}

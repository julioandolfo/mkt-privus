<?php

namespace App\Services\Social\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\PublishResult;
use Illuminate\Support\Str;

/**
 * Publisher para Pinterest.
 * TODO: Integrar com Pinterest API quando APIs forem configuradas.
 */
class PinterestPublisher extends AbstractPublisher
{
    protected function platformName(): string
    {
        return 'Pinterest';
    }

    protected function doPublish(Post $post, SocialAccount $account): PublishResult
    {
        // Simulacao - sera substituido pela integracao com Pinterest API
        // POST https://api.pinterest.com/v5/pins

        usleep(500000);

        $fakePostId = 'pin_' . Str::random(15);
        $fakeUrl = "https://www.pinterest.com/pin/{$fakePostId}/";

        return PublishResult::ok($fakePostId, $fakeUrl);
    }
}

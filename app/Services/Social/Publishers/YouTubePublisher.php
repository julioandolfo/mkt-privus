<?php

namespace App\Services\Social\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\PublishResult;
use Illuminate\Support\Str;

/**
 * Publisher para YouTube.
 * TODO: Integrar com YouTube Data API v3 quando APIs forem configuradas.
 */
class YouTubePublisher extends AbstractPublisher
{
    protected function platformName(): string
    {
        return 'YouTube';
    }

    protected function doPublish(Post $post, SocialAccount $account): PublishResult
    {
        // Simulacao - sera substituido pela integracao com YouTube Data API
        // POST https://www.googleapis.com/upload/youtube/v3/videos

        usleep(500000);

        $fakePostId = 'yt_' . Str::random(11);
        $fakeUrl = "https://www.youtube.com/watch?v={$fakePostId}";

        return PublishResult::ok($fakePostId, $fakeUrl);
    }
}

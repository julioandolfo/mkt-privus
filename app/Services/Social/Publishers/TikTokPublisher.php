<?php

namespace App\Services\Social\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\PublishResult;
use Illuminate\Support\Str;

/**
 * Publisher para TikTok.
 * TODO: Integrar com TikTok Content Posting API quando APIs forem configuradas.
 */
class TikTokPublisher extends AbstractPublisher
{
    protected function platformName(): string
    {
        return 'TikTok';
    }

    protected function doPublish(Post $post, SocialAccount $account): PublishResult
    {
        // Simulacao - sera substituido pela integracao com TikTok API
        // POST https://open.tiktokapis.com/v2/post/publish/content/init/

        usleep(500000);

        $fakePostId = 'tt_' . Str::random(15);
        $fakeUrl = "https://www.tiktok.com/@{$account->username}/video/{$fakePostId}";

        return PublishResult::ok($fakePostId, $fakeUrl);
    }
}

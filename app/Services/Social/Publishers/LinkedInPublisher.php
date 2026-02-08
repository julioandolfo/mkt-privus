<?php

namespace App\Services\Social\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\PublishResult;
use Illuminate\Support\Str;

/**
 * Publisher para LinkedIn.
 * TODO: Integrar com LinkedIn Marketing API quando APIs forem configuradas.
 */
class LinkedInPublisher extends AbstractPublisher
{
    protected function platformName(): string
    {
        return 'LinkedIn';
    }

    protected function doPublish(Post $post, SocialAccount $account): PublishResult
    {
        // Simulacao - sera substituido pela integracao com LinkedIn API
        // POST https://api.linkedin.com/v2/ugcPosts

        usleep(500000);

        $fakePostId = 'li_' . Str::random(15);
        $fakeUrl = "https://www.linkedin.com/feed/update/urn:li:share:{$fakePostId}";

        return PublishResult::ok($fakePostId, $fakeUrl);
    }
}

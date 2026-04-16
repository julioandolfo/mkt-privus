<?php

namespace Tests\Feature;

use App\Enums\SocialPlatform;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\SocialAccount;
use App\Services\Social\Publishers\PostizPublisher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostizPublisherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Cria apenas a tabela system_logs (SystemLog é chamado pelo publisher).
        // Evita rodar todas migrations que têm incompatibilidades com sqlite.
        if (!Schema::hasTable('system_logs')) {
            Schema::create('system_logs', function ($t) {
                $t->id();
                $t->string('channel')->nullable();
                $t->string('level')->nullable();
                $t->string('action')->nullable();
                $t->text('message')->nullable();
                $t->json('context')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('brand_id')->nullable();
                $t->string('ip')->nullable();
                $t->timestamps();
            });
        }
    }

    public function test_fails_if_account_has_no_postiz_integration_id(): void
    {
        $account = new SocialAccount([
            'platform' => SocialPlatform::LinkedIn,
            'source' => 'postiz',
            'username' => 'nevo',
            'is_active' => true,
        ]);
        $account->setRelation('media', new Collection());

        $post = new Post(['caption' => 'hello']);
        $post->setRelation('media', new Collection());

        $publisher = new PostizPublisher(SocialPlatform::LinkedIn);
        $result = $publisher->publish($post, $account);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Postiz', $result->errorMessage);
    }

    public function test_builds_payload_with_correct_platform_settings(): void
    {
        config()->set('services.postiz.url', 'https://api.postiz.test/public/v1');
        config()->set('services.postiz.api_key', 'key');
        config()->set('app.url', 'https://app.example.com');

        Http::fake([
            'api.postiz.test/public/v1/upload-from-url' => Http::response([
                'id' => 'img1',
                'path' => 'https://uploads.postiz.com/x.jpg',
            ], 200),
            'api.postiz.test/public/v1/posts' => Http::response([
                'data' => [['postId' => 'p-123', 'integration' => 'int-1']],
            ], 200),
        ]);

        $post = new Post(['caption' => 'teste', 'hashtags' => ['marketing', 'b2b']]);
        $media = new PostMedia(['file_path' => 'posts/abc.jpg', 'type' => 'image', 'order' => 0]);
        $post->setRelation('media', new Collection([$media]));

        $account = new SocialAccount([
            'platform' => SocialPlatform::LinkedInPage,
            'source' => 'postiz',
            'postiz_integration_id' => 'int-1',
            'username' => 'acme',
            'is_active' => true,
        ]);

        $publisher = new PostizPublisher(SocialPlatform::LinkedInPage);
        $result = $publisher->publish($post, $account);

        $this->assertTrue($result->success, $result->errorMessage ?? '');
        $this->assertSame('p-123', $result->platformPostId);

        Http::assertSent(function ($req) {
            if (!str_contains($req->url(), '/posts')) {
                return true;
            }
            $body = $req->data();
            $settings = $body['posts'][0]['settings'] ?? [];
            return $settings['__type'] === 'linkedin-page';
        });
    }

    public function test_tiktok_settings_include_privacy_and_toggles(): void
    {
        config()->set('services.postiz.url', 'https://api.postiz.test/public/v1');
        config()->set('services.postiz.api_key', 'key');
        config()->set('app.url', 'https://app.example.com');

        Http::fake([
            'api.postiz.test/public/v1/upload-from-url' => Http::response(['id' => 'i', 'path' => 'p'], 200),
            'api.postiz.test/public/v1/posts' => Http::response(['data' => [['postId' => 'x', 'integration' => 'i']]], 200),
        ]);

        $post = new Post(['caption' => 'clip']);
        $post->setRelation('media', new Collection([
            new PostMedia(['file_path' => 'v.mp4', 'type' => 'video', 'order' => 0]),
        ]));

        $account = new SocialAccount([
            'platform' => SocialPlatform::TikTok,
            'source' => 'postiz',
            'postiz_integration_id' => 'int-tt',
            'username' => 'tok',
            'is_active' => true,
        ]);

        (new PostizPublisher(SocialPlatform::TikTok))->publish($post, $account);

        Http::assertSent(function ($req) {
            if (!str_contains($req->url(), '/posts')) {
                return true;
            }
            $settings = $req->data()['posts'][0]['settings'] ?? [];
            return $settings['__type'] === 'tiktok'
                && $settings['privacy_level'] === 'PUBLIC_TO_EVERYONE'
                && $settings['content_posting_method'] === 'DIRECT_POST';
        });
    }
}

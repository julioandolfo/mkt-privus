<?php

namespace Tests\Feature;

use App\Services\Social\Postiz\PostizGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PostizGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

    private function gateway(): PostizGateway
    {
        return new PostizGateway(
            baseUrl: 'https://api.postiz.test/public/v1',
            apiKey: 'test-api-key',
        );
    }

    public function test_is_configured_reflects_api_key_presence(): void
    {
        $configured = new PostizGateway('https://api.postiz.test/public/v1', 'key');
        $this->assertTrue($configured->isConfigured());

        $empty = new PostizGateway('https://api.postiz.test/public/v1', null);
        $this->assertFalse($empty->isConfigured());
    }

    public function test_list_integrations_returns_array(): void
    {
        Http::fake([
            'api.postiz.test/public/v1/integrations' => Http::response([
                ['id' => 'abc', 'name' => 'Nevo', 'identifier' => 'linkedin', 'picture' => null, 'disabled' => false, 'profile' => 'nevo'],
            ], 200),
        ]);

        $result = $this->gateway()->listIntegrations();

        $this->assertCount(1, $result);
        $this->assertSame('abc', $result[0]['id']);
        Http::assertSent(fn($req) => $req->header('Authorization')[0] === 'test-api-key');
    }

    public function test_generate_oauth_url_returns_url(): void
    {
        Http::fake([
            'api.postiz.test/public/v1/social/linkedin' => Http::response(['url' => 'https://linkedin.com/oauth?x=1'], 200),
        ]);

        $url = $this->gateway()->generateOAuthUrl('linkedin');

        $this->assertSame('https://linkedin.com/oauth?x=1', $url);
    }

    public function test_generate_oauth_url_throws_when_empty(): void
    {
        Http::fake([
            'api.postiz.test/public/v1/social/tiktok' => Http::response(['url' => null], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->gateway()->generateOAuthUrl('tiktok');
    }

    public function test_upload_from_url_returns_id_and_path(): void
    {
        Http::fake([
            'api.postiz.test/public/v1/upload-from-url' => Http::response([
                'id' => 'img-1',
                'path' => 'https://uploads.postiz.com/x.jpg',
            ], 200),
        ]);

        $result = $this->gateway()->uploadFromUrl('https://example.com/a.jpg');

        $this->assertSame('img-1', $result['id']);
        $this->assertSame('https://uploads.postiz.com/x.jpg', $result['path']);
    }

    public function test_create_post_returns_data_array(): void
    {
        Http::fake([
            'api.postiz.test/public/v1/posts' => Http::response([
                'status' => 200,
                'data' => [['postId' => 'p1', 'integration' => 'int1']],
            ], 200),
        ]);

        $result = $this->gateway()->createPost(['type' => 'now']);

        $this->assertCount(1, $result);
        $this->assertSame('p1', $result[0]['postId']);
    }

    public function test_delete_integration_ignores_404(): void
    {
        Http::fake([
            'api.postiz.test/public/v1/integrations/abc' => Http::response([], 404),
        ]);

        $this->gateway()->deleteIntegration('abc');

        // Nada é esperado retornar — só garante que não lançou.
        $this->assertTrue(true);
    }

    public function test_api_error_wraps_failed_response(): void
    {
        Http::fake([
            'api.postiz.test/public/v1/integrations' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Falha ao listar.*401.*Unauthorized/i');

        $this->gateway()->listIntegrations();
    }

    public function test_unconfigured_gateway_throws_before_hitting_http(): void
    {
        Http::fake();

        $gateway = new PostizGateway('https://api.postiz.test/public/v1', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POSTIZ_API_KEY');

        $gateway->listIntegrations();
    }
}

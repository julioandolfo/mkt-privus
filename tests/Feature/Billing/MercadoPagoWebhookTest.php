<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    private function signedHeaders(string $dataId, ?string $secret = null): array
    {
        $ts = (string) now()->getTimestampMs();
        $requestId = 'req-123';
        $manifest = 'id:'.strtolower($dataId).";request-id:{$requestId};ts:{$ts};";
        $hmac = hash_hmac('sha256', $manifest, $secret ?? self::SECRET);

        return [
            'x-signature' => "ts={$ts},v1={$hmac}",
            'x-request-id' => $requestId,
        ];
    }

    public function test_rejects_invalid_signature(): void
    {
        config(['billing.mercadopago.webhook_secret' => self::SECRET]);

        $this->postJson('/webhook/mercadopago?data_id=abc123', [
            'type' => 'subscription_preapproval',
            'data' => ['id' => 'abc123'],
        ], $this->signedHeaders('abc123', 'wrong-secret'))
            ->assertUnauthorized();
    }

    public function test_activates_subscription_on_authorized_preapproval(): void
    {
        config(['billing.mercadopago.webhook_secret' => self::SECRET]);

        $plan = Plan::factory()->create();
        $user = User::factory()->create();
        $subscription = $user->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
            'mp_preapproval_id' => 'mp-pre-1',
        ]);

        Http::fake([
            '*/preapproval/mp-pre-1' => Http::response([
                'id' => 'mp-pre-1',
                'status' => 'authorized',
                'external_reference' => (string) $user->id,
                'next_payment_date' => now()->addMonth()->toIso8601String(),
            ]),
        ]);

        $this->postJson('/webhook/mercadopago?data_id=mp-pre-1', [
            'type' => 'subscription_preapproval',
            'data' => ['id' => 'mp-pre-1'],
        ], $this->signedHeaders('mp-pre-1'))
            ->assertOk();

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNotNull($subscription->current_period_end);
        $this->assertTrue($user->fresh()->hasValidSubscription() || !config('billing.enabled'));
    }

    public function test_cancels_subscription_on_cancelled_preapproval(): void
    {
        config(['billing.mercadopago.webhook_secret' => self::SECRET]);

        $plan = Plan::factory()->create();
        $user = User::factory()->create();
        $subscription = $user->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'mp_preapproval_id' => 'mp-pre-2',
        ]);

        Http::fake([
            '*/preapproval/mp-pre-2' => Http::response([
                'id' => 'mp-pre-2',
                'status' => 'cancelled',
                'external_reference' => (string) $user->id,
            ]),
        ]);

        $this->postJson('/webhook/mercadopago?data_id=mp-pre-2', [
            'type' => 'subscription_preapproval',
            'data' => ['id' => 'mp-pre-2'],
        ], $this->signedHeaders('mp-pre-2'))
            ->assertOk();

        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    public function test_ignores_unrelated_event_types(): void
    {
        config(['billing.mercadopago.webhook_secret' => self::SECRET]);

        $this->postJson('/webhook/mercadopago?data_id=pay-1', [
            'type' => 'payment',
            'data' => ['id' => 'pay-1'],
        ], $this->signedHeaders('pay-1'))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
    }
}

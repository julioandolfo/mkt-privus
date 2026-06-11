<?php

namespace Tests\Feature;

use App\Enums\BrandRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_brand_and_sets_owner(): void
    {
        $response = $this->post('/register', [
            'name' => 'Julio Teste',
            'email' => 'julio@example.com',
            'brand_name' => 'Privus Marketing',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'julio@example.com')->firstOrFail();
        $brand = $user->brands()->first();

        $this->assertNotNull($brand);
        $this->assertSame('Privus Marketing', $brand->name);
        $this->assertSame(BrandRole::Owner, $user->roleInBrand($brand));
        $this->assertSame($brand->id, $user->current_brand_id);
    }

    public function test_registration_starts_trial_when_billing_enabled(): void
    {
        config(['billing.enabled' => true, 'billing.trial_days' => 14]);
        $plan = Plan::factory()->create();

        $this->post('/register', [
            'name' => 'Julio Trial',
            'email' => 'trial@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'trial@example.com')->firstOrFail();
        $subscription = $user->subscriptions()->first();

        $this->assertNotNull($subscription);
        $this->assertSame(Subscription::STATUS_TRIALING, $subscription->status);
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertTrue($subscription->trial_ends_at->isFuture());
        $this->assertTrue($user->hasValidSubscription());
    }

    public function test_user_without_subscription_is_redirected_to_billing(): void
    {
        config(['billing.enabled' => true]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('billing.index'));
    }

    public function test_billing_disabled_does_not_block_access(): void
    {
        config(['billing.enabled' => false]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }
}

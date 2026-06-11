<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_non_admin_cannot_access_accounts_backoffice(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.accounts.index'))->assertForbidden();
    }

    public function test_admin_can_list_accounts(): void
    {
        User::factory()->count(3)->create();

        $this->actingAs($this->admin())
            ->get(route('admin.accounts.index'))
            ->assertOk();
    }

    public function test_admin_can_extend_trial(): void
    {
        Plan::factory()->create();
        $customer = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.accounts.extend-trial', $customer->id), ['days' => 10])
            ->assertSessionHas('success');

        $subscription = $customer->subscriptions()->first();
        $this->assertSame(Subscription::STATUS_TRIALING, $subscription->status);
        $this->assertTrue($subscription->trial_ends_at->isFuture());
        $this->assertEqualsWithDelta(10, now()->diffInDays($subscription->trial_ends_at), 1);
    }

    public function test_admin_can_grant_courtesy_plan(): void
    {
        $plan = Plan::factory()->create();
        $customer = User::factory()->create();
        $oldTrial = $customer->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(3),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.accounts.grant-plan', $customer->id), [
                'plan_id' => $plan->id,
                'months' => null,
            ])
            ->assertSessionHas('success');

        $this->assertSame(Subscription::STATUS_CANCELLED, $oldTrial->fresh()->status);

        $granted = $customer->subscriptions()->latest('id')->first();
        $this->assertSame(Subscription::STATUS_ACTIVE, $granted->status);
        $this->assertNull($granted->current_period_end);
        $this->assertTrue($granted->isValid());
    }

    public function test_admin_cannot_deactivate_or_demote_self(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.accounts.toggle-active', $admin->id))
            ->assertSessionHas('error');

        $this->actingAs($admin)
            ->post(route('admin.accounts.toggle-admin', $admin->id))
            ->assertSessionHas('error');

        $admin->refresh();
        $this->assertTrue($admin->is_admin);
        $this->assertNotFalse($admin->is_active);
    }

    public function test_deactivated_account_cannot_login(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_admin_can_cancel_subscription(): void
    {
        $plan = Plan::factory()->create();
        $customer = User::factory()->create();
        $subscription = $customer->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.accounts.cancel-subscription', $customer->id))
            ->assertSessionHas('success');

        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_super_admin_with_explicit_credentials(): void
    {
        $this->artisan('admin:create', [
            'email' => 'owner@saas.com',
            '--name' => 'Operador',
            '--password' => 'secret-password-123',
        ])->assertSuccessful();

        $user = User::where('email', 'owner@saas.com')->firstOrFail();
        $this->assertTrue($user->is_admin);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_promotes_existing_user(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->artisan('admin:create', ['email' => $user->email])->assertSuccessful();

        $this->assertTrue($user->fresh()->is_admin);
    }

    public function test_ensure_is_noop_when_admin_exists(): void
    {
        User::factory()->create(['is_admin' => true]);

        $this->artisan('admin:create', ['--ensure' => true])->assertSuccessful();

        $this->assertSame(1, User::where('is_admin', true)->count());
    }

    public function test_ensure_without_email_does_not_fail_deploy(): void
    {
        $this->artisan('admin:create', ['--ensure' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_ensure_creates_admin_when_none_exists(): void
    {
        User::factory()->count(2)->create(['is_admin' => false]);

        $this->artisan('admin:create', [
            'email' => 'boot@saas.com',
            '--password' => 'boot-password-123',
            '--ensure' => true,
        ])->assertSuccessful();

        $this->assertTrue(User::where('email', 'boot@saas.com')->firstOrFail()->is_admin);
    }

    public function test_admin_bypasses_subscription_requirement_and_limits(): void
    {
        config(['billing.enabled' => true]);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $usage = app(\App\Services\Billing\UsageService::class);
        $this->assertTrue($usage->canCreateBrand($admin));
        $this->assertTrue($usage->canUseAi($admin));
    }
}

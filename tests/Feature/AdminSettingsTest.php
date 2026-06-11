<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Support\BillingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_settings_or_logs(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/settings')->assertForbidden();
        $this->actingAs($user)->get('/logs')->assertForbidden();
    }

    public function test_admin_can_access_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/settings')->assertOk();
    }

    public function test_admin_can_update_billing_settings_via_ui(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->put(route('settings.billing'), [
                'enabled' => true,
                'trial_days' => 21,
                'mp_public_key' => 'APP_USR-public',
                'mp_access_token' => 'APP_USR-secret-token',
                'mp_webhook_secret' => 'webhook-secret',
                'sendpulse_webhook_token' => 'sp-token',
                'admin_alert_email' => 'admin@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // Configurações do banco têm prioridade sobre o .env
        $this->assertTrue(BillingSettings::enabled());
        $this->assertSame(21, BillingSettings::trialDays());
        $this->assertSame('APP_USR-secret-token', BillingSettings::mpAccessToken());
        $this->assertSame('webhook-secret', BillingSettings::mpWebhookSecret());
        $this->assertSame('sp-token', BillingSettings::sendpulseWebhookToken());
        $this->assertSame('admin@example.com', BillingSettings::adminAlertEmail());
    }

    public function test_empty_secret_fields_keep_current_values(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Setting::set('billing', 'mp_access_token', 'token-original', 'encrypted');

        $this->actingAs($admin)->put(route('settings.billing'), [
            'enabled' => false,
            'trial_days' => 14,
            'mp_access_token' => '',
        ]);

        $this->assertSame('token-original', BillingSettings::mpAccessToken());
    }

    public function test_admin_can_update_plan(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = Plan::factory()->create(['price' => 97]);

        $this->actingAs($admin)
            ->put(route('settings.plans.update', $plan->id), [
                'name' => 'Plano Editado',
                'price' => 149.9,
                'is_active' => true,
                'features' => ['Tudo ilimitado', ''],
                'limits' => [
                    'max_brands' => 5,
                    'max_users' => null,
                    'monthly_emails' => 99000,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $plan->refresh();
        $this->assertSame('Plano Editado', $plan->name);
        $this->assertSame(149.9, (float) $plan->price);
        $this->assertSame(['Tudo ilimitado'], $plan->features);
        $this->assertSame(5, $plan->limit('max_brands'));
        $this->assertNull($plan->limit('max_users'));
        $this->assertSame(99000, $plan->limit('monthly_emails'));
    }

    public function test_non_admin_cannot_update_plans_or_billing(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $plan = Plan::factory()->create();

        $this->actingAs($user)
            ->put(route('settings.billing'), ['enabled' => true, 'trial_days' => 14])
            ->assertForbidden();

        $this->actingAs($user)
            ->put(route('settings.plans.update', $plan->id), ['name' => 'X', 'price' => 1, 'is_active' => true])
            ->assertForbidden();
    }

    public function test_billing_settings_fall_back_to_env_config(): void
    {
        config(['billing.enabled' => true, 'billing.trial_days' => 7]);

        $this->assertTrue(BillingSettings::enabled());
        $this->assertSame(7, BillingSettings::trialDays());
    }
}

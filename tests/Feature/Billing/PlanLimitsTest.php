<?php

namespace Tests\Feature\Billing;

use App\Enums\BrandRole;
use App\Models\AiUsageLog;
use App\Models\Brand;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function subscribedOwner(array $limits): array
    {
        $plan = Plan::factory()->create(['limits' => $limits]);
        $user = User::factory()->create();
        $user->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $brand = Brand::factory()->create();
        $brand->users()->attach($user->id, ['role' => BrandRole::Owner->value]);
        $user->update(['current_brand_id' => $brand->id]);

        return [$user, $brand];
    }

    public function test_brand_limit_is_enforced(): void
    {
        config(['billing.enabled' => true]);

        [$user] = $this->subscribedOwner(['max_brands' => 1]);

        $usage = app(UsageService::class);
        $this->assertFalse($usage->canCreateBrand($user));

        $this->actingAs($user)
            ->post('/brands', ['name' => 'Segunda Marca'])
            ->assertRedirect(route('billing.index'));

        $this->assertSame(1, $usage->brandsCount($user));
    }

    public function test_ai_token_limit_is_enforced(): void
    {
        config(['billing.enabled' => true]);

        [$user, $brand] = $this->subscribedOwner(['monthly_ai_tokens' => 1000]);

        $usage = app(UsageService::class);
        $this->assertTrue($usage->canUseAi($user, $brand));

        AiUsageLog::create([
            'user_id' => $user->id,
            'brand_id' => $brand->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'feature' => 'chat',
            'input_tokens' => 800,
            'output_tokens' => 400,
            'estimated_cost' => 0.001,
        ]);

        $this->assertFalse($usage->canUseAi($user, $brand));
    }

    public function test_limits_are_ignored_when_billing_disabled(): void
    {
        config(['billing.enabled' => false]);

        $user = User::factory()->create();

        $usage = app(UsageService::class);
        $this->assertTrue($usage->canCreateBrand($user));
        $this->assertTrue($usage->canUseAi($user));
    }

    public function test_sms_limit_is_enforced(): void
    {
        config(['billing.enabled' => true]);

        [, $brand] = $this->subscribedOwner(['monthly_sms' => 100]);

        $usage = app(UsageService::class);
        $this->assertTrue($usage->canSendSms($brand, 50));
        $this->assertFalse($usage->canSendSms($brand, 150));
    }

    public function test_member_consumes_owner_quota(): void
    {
        config(['billing.enabled' => true]);

        [$owner, $brand] = $this->subscribedOwner(['monthly_ai_tokens' => 1000]);

        $editor = User::factory()->create();
        $brand->users()->attach($editor->id, ['role' => BrandRole::Editor->value]);

        AiUsageLog::create([
            'user_id' => $editor->id,
            'brand_id' => $brand->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'feature' => 'chat',
            'input_tokens' => 900,
            'output_tokens' => 200,
            'estimated_cost' => 0.001,
        ]);

        $usage = app(UsageService::class);

        // Uso do editor conta contra o plano do Owner da marca
        $this->assertFalse($usage->canUseAi($editor, $brand));
        $this->assertSame(1100, $usage->aiTokensThisMonth($owner));
    }
}

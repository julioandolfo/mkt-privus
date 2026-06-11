<?php

namespace Tests\Feature;

use App\Enums\BrandRole;
use App\Models\Brand;
use App\Models\EmailCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Brand} */
    private function userWithBrand(): array
    {
        $user = User::factory()->create();
        $brand = Brand::factory()->create();
        $brand->users()->attach($user->id, ['role' => BrandRole::Owner->value]);
        $user->update(['current_brand_id' => $brand->id]);

        return [$user, $brand];
    }

    private function campaignFor(Brand $brand, User $user): EmailCampaign
    {
        return EmailCampaign::create([
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'name' => 'Campanha de teste',
            'subject' => 'Assunto de teste',
        ]);
    }

    public function test_user_cannot_see_campaigns_of_other_brands(): void
    {
        [$userA, $brandA] = $this->userWithBrand();
        [$userB] = $this->userWithBrand();

        $campaign = $this->campaignFor($brandA, $userA);

        $this->actingAs($userB);
        $this->assertNull(EmailCampaign::find($campaign->id));

        $this->actingAs($userA);
        $this->assertNotNull(EmailCampaign::find($campaign->id));
    }

    public function test_route_model_binding_returns_404_for_other_brands_campaign(): void
    {
        [$userA, $brandA] = $this->userWithBrand();
        [$userB] = $this->userWithBrand();

        $campaign = $this->campaignFor($brandA, $userA);

        $this->actingAs($userB)
            ->get(route('email.campaigns.show', $campaign->id))
            ->assertNotFound();
    }

    public function test_brand_id_is_filled_automatically_on_create(): void
    {
        [$userA, $brandA] = $this->userWithBrand();

        $this->actingAs($userA);

        $campaign = EmailCampaign::create([
            'user_id' => $userA->id,
            'name' => 'Sem marca explícita',
            'subject' => 'Teste',
        ]);

        $this->assertSame($brandA->id, $campaign->brand_id);
    }

    public function test_user_without_active_brand_sees_nothing(): void
    {
        [$userA, $brandA] = $this->userWithBrand();
        $campaign = $this->campaignFor($brandA, $userA);

        $orphan = User::factory()->create(['current_brand_id' => null]);

        $this->actingAs($orphan);
        $this->assertNull(EmailCampaign::find($campaign->id));
    }

    public function test_user_cannot_edit_or_switch_to_brand_they_dont_belong_to(): void
    {
        [, $brandA] = $this->userWithBrand();
        [$userB] = $this->userWithBrand();

        $this->actingAs($userB)
            ->get(route('brands.edit', $brandA->id))
            ->assertForbidden();

        $this->actingAs($userB)
            ->post(route('brands.switch', $brandA->id))
            ->assertForbidden();
    }

    public function test_viewer_cannot_manage_brand(): void
    {
        [, $brand] = $this->userWithBrand();

        $viewer = User::factory()->create();
        $brand->users()->attach($viewer->id, ['role' => BrandRole::Viewer->value]);
        $viewer->update(['current_brand_id' => $brand->id]);

        $this->actingAs($viewer)
            ->get(route('brands.edit', $brand->id))
            ->assertForbidden();
    }

    public function test_unauthenticated_context_is_not_scoped(): void
    {
        [$userA, $brandA] = $this->userWithBrand();
        $campaign = $this->campaignFor($brandA, $userA);

        // Sem usuário autenticado (jobs/webhooks), a query enxerga tudo
        $this->assertNotNull(EmailCampaign::find($campaign->id));
    }
}

<?php

namespace Tests\Feature;

use App\Enums\BrandRole;
use App\Jobs\RefreshSocialTokensJob;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private function userWithBrands(): array
    {
        $user = User::factory()->create();
        $brandA = Brand::factory()->create(['name' => 'Marca A']);
        $brandB = Brand::factory()->create(['name' => 'Marca B']);
        $brandA->users()->attach($user->id, ['role' => BrandRole::Owner->value]);
        $brandB->users()->attach($user->id, ['role' => BrandRole::Owner->value]);
        $user->update(['current_brand_id' => $brandA->id]);

        return [$user, $brandA, $brandB];
    }

    // ===== Fix 2: vincular marca entre marcas (não pode dar 404/erro) =====

    public function test_link_brand_works_for_account_outside_active_brand(): void
    {
        [$user, $brandA, $brandB] = $this->userWithBrands();

        // Conta atribuída a uma marca DIFERENTE da ativa (cenário que antes
        // causava 404 no route-model binding e "Erro ao vincular marca").
        $account = SocialAccount::withoutGlobalScope('brand')->create([
            'brand_id' => $brandB->id,
            'platform' => 'instagram',
            'username' => 'conta_b',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson(route('social.accounts.link-brand', $account->id), [
                'brand_id' => $brandA->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'brand_id' => $brandA->id]);

        $this->assertSame($brandA->id, $account->fresh()->brand_id);
    }

    public function test_link_brand_denies_account_of_brand_user_is_not_member_of(): void
    {
        [$userA, $brandA] = $this->userWithBrands();

        // Marca/conta de outro tenant — userA não é membro.
        $otherUser = User::factory()->create();
        $otherBrand = Brand::factory()->create(['name' => 'Outro Tenant']);
        $otherBrand->users()->attach($otherUser->id, ['role' => BrandRole::Owner->value]);

        $isolated = SocialAccount::withoutGlobalScope('brand')->create([
            'brand_id' => $otherBrand->id,
            'platform' => 'instagram',
            'username' => 'isolada',
            'is_active' => true,
        ]);

        $this->actingAs($userA)
            ->postJson(route('social.accounts.link-brand', $isolated->id), [
                'brand_id' => $brandA->id,
            ])
            ->assertNotFound();

        // Conta intacta — nenhum vazamento entre marcas.
        $this->assertSame($otherBrand->id, $isolated->fresh()->brand_id);
    }

    public function test_link_brand_rejects_target_brand_user_is_not_member_of(): void
    {
        [$userA, $brandA] = $this->userWithBrands();

        $otherBrand = Brand::factory()->create(['name' => 'Marca de terceiro']);

        $account = SocialAccount::withoutGlobalScope('brand')->create([
            'brand_id' => $brandA->id,
            'platform' => 'instagram',
            'username' => 'conta',
            'is_active' => true,
        ]);

        // Tentar vincular a uma marca que não é do usuário deve falhar (404).
        $this->actingAs($userA)
            ->postJson(route('social.accounts.link-brand', $account->id), [
                'brand_id' => $otherBrand->id,
            ])
            ->assertNotFound();

        $this->assertSame($brandA->id, $account->fresh()->brand_id);
    }

    // ===== Fix 1: renovação automática de token =====

    public function test_meta_account_can_auto_refresh_without_refresh_token(): void
    {
        [, $brandA] = $this->userWithBrands();

        $account = SocialAccount::withoutGlobalScope('brand')->create([
            'brand_id' => $brandA->id,
            'platform' => 'instagram',
            'username' => 'meta',
            'access_token' => 'valid-token',
            'refresh_token' => null,
            'token_expires_at' => now()->addDays(3),
            'is_active' => true,
        ]);

        // Meta não usa refresh_token; renova via fb_exchange_token enquanto válido.
        $this->assertTrue($account->canAutoRefresh());

        $account->token_expires_at = now()->subDay();
        // Token já expirado não pode mais ser estendido pela Meta.
        $this->assertFalse($account->canAutoRefresh());
    }

    public function test_google_account_requires_refresh_token(): void
    {
        [, $brandA] = $this->userWithBrands();

        $account = SocialAccount::withoutGlobalScope('brand')->create([
            'brand_id' => $brandA->id,
            'platform' => 'youtube',
            'username' => 'yt',
            'access_token' => 'valid-token',
            'refresh_token' => null,
            'token_expires_at' => now()->addMinutes(30),
            'is_active' => true,
        ]);

        $this->assertFalse($account->canAutoRefresh());

        $account->refresh_token = 'a-refresh-token';
        $this->assertTrue($account->canAutoRefresh());
    }

    public function test_refresh_job_renews_meta_account_without_refresh_token(): void
    {
        [, $brandA] = $this->userWithBrands();

        $account = SocialAccount::withoutGlobalScope('brand')->create([
            'brand_id' => $brandA->id,
            'platform' => 'instagram',
            'username' => 'meta',
            'access_token' => 'old-token',
            'refresh_token' => null,
            'token_expires_at' => now()->addDays(3), // dentro da janela proativa de 7 dias
            'is_active' => true,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'access_token' => 'brand-new-token',
                'expires_in' => 5184000,
            ], 200),
        ]);

        app(RefreshSocialTokensJob::class)->handle(app(\App\Services\Social\SocialOAuthService::class));

        $account->refresh();
        $this->assertSame('brand-new-token', $account->access_token);
        $this->assertTrue($account->token_expires_at->isAfter(now()->addDays(30)));
    }
}

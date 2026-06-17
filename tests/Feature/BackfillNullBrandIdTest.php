<?php

namespace Tests\Feature;

use App\Actions\BackfillNullBrandId;
use App\Enums\BrandRole;
use App\Models\Brand;
use App\Models\EmailCampaign;
use App\Models\EmailContact;
use App\Models\EmailList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillNullBrandIdTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Brand} */
    private function ownerWithBrand(): array
    {
        $user = User::factory()->create();
        $brand = Brand::factory()->create();
        $brand->users()->attach($user->id, ['role' => BrandRole::Owner->value]);
        $user->update(['current_brand_id' => $brand->id]);

        return [$user, $brand];
    }

    public function test_backfills_user_owned_rows_to_owner_active_brand(): void
    {
        [$user, $brand] = $this->ownerWithBrand();

        // Simula dado legado: criado sem marca (brand_id NULL), como o app antigo fazia
        $campaign = EmailCampaign::withoutBrandScope()->create([
            'brand_id' => null,
            'user_id' => $user->id,
            'name' => 'Campanha legada',
            'subject' => 'Assunto',
        ]);
        $list = EmailList::withoutBrandScope()->create([
            'brand_id' => null,
            'user_id' => $user->id,
            'name' => 'Lista legada',
        ]);

        $counts = app(BackfillNullBrandId::class)->handle();

        $this->assertSame($brand->id, $campaign->fresh()->brand_id);
        $this->assertSame($brand->id, $list->fresh()->brand_id);
        $this->assertGreaterThanOrEqual(2, array_sum($counts));
    }

    public function test_restores_visibility_under_brand_scope(): void
    {
        [$user, $brand] = $this->ownerWithBrand();

        EmailList::withoutBrandScope()->create([
            'brand_id' => null,
            'user_id' => $user->id,
            'name' => 'Lista invisível antes do backfill',
        ]);

        // Antes do backfill: escopo estrito esconde a lista com brand_id NULL
        $this->actingAs($user);
        $this->assertSame(0, EmailList::count());

        // Sem auth (console), roda o backfill
        auth()->logout();
        app(BackfillNullBrandId::class)->handle();

        $this->actingAs($user);
        $this->assertSame(1, EmailList::count());
    }

    public function test_backfills_email_contacts_from_their_list(): void
    {
        [$user, $brand] = $this->ownerWithBrand();

        $list = EmailList::withoutBrandScope()->create([
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'name' => 'Lista',
        ]);
        $contact = EmailContact::withoutBrandScope()->create([
            'brand_id' => null,
            'email' => 'legado@example.com',
        ]);
        DB::table('email_list_contact')->insert([
            'email_list_id' => $list->id,
            'email_contact_id' => $contact->id,
        ]);

        app(BackfillNullBrandId::class)->handle();

        $this->assertSame($brand->id, $contact->fresh()->brand_id);
    }

    public function test_is_idempotent_and_leaves_assigned_rows_untouched(): void
    {
        [$user, $brand] = $this->ownerWithBrand();
        $other = Brand::factory()->create();

        // Já vinculada a outra marca: o backfill não pode mexer
        $list = EmailList::withoutBrandScope()->create([
            'brand_id' => $other->id,
            'user_id' => $user->id,
            'name' => 'Lista de outra marca',
        ]);

        app(BackfillNullBrandId::class)->handle();
        app(BackfillNullBrandId::class)->handle(); // segunda passada: sem efeito

        $this->assertSame($other->id, $list->fresh()->brand_id);
    }
}

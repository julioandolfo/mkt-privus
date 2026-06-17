<?php

namespace Tests\Feature;

use App\Enums\BrandRole;
use App\Models\Brand;
use App\Models\EmailList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Garante que o filtro por marca dos controllers usa a coluna durável
 * users.current_brand_id (e não a sessão), funcionando de forma consistente.
 */
class BrandScopeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_lists_index_shows_only_active_brand_without_session(): void
    {
        $user = User::factory()->create();

        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();
        $brandA->users()->attach($user->id, ['role' => BrandRole::Owner->value]);
        $brandB->users()->attach($user->id, ['role' => BrandRole::Owner->value]);
        $user->update(['current_brand_id' => $brandA->id]);

        EmailList::withoutBrandScope()->create(['brand_id' => $brandA->id, 'user_id' => $user->id, 'name' => 'Lista A']);
        EmailList::withoutBrandScope()->create(['brand_id' => $brandB->id, 'user_id' => $user->id, 'name' => 'Lista B']);

        // Sem nada na sessão: a marca ativa vem do banco
        $this->actingAs($user)
            ->get(route('email.lists.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Email/Lists/Index')
                ->has('lists', 1)
                ->where('lists.0.name', 'Lista A'));

        // Troca de marca persiste no banco e muda o que é listado
        $user->update(['current_brand_id' => $brandB->id]);

        $this->actingAs($user)
            ->get(route('email.lists.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('lists', 1)
                ->where('lists.0.name', 'Lista B'));
    }

    public function test_new_list_is_created_with_active_brand_from_db(): void
    {
        $user = User::factory()->create();
        $brand = Brand::factory()->create();
        $brand->users()->attach($user->id, ['role' => BrandRole::Owner->value]);
        $user->update(['current_brand_id' => $brand->id]);

        $this->actingAs($user)
            ->post(route('email.lists.store'), ['name' => 'Nova lista'])
            ->assertRedirect();

        $list = EmailList::withoutBrandScope()->where('name', 'Nova lista')->firstOrFail();
        $this->assertSame($brand->id, $list->brand_id);
    }
}

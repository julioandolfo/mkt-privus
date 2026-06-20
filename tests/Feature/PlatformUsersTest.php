<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Configurações → Usuários gerencia a equipe da plataforma. Não pode mais
 * vincular usuários a todas as marcas (vazamento entre tenants).
 */
class PlatformUsersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_created_user_is_not_attached_to_any_brand(): void
    {
        Brand::factory()->count(3)->create();

        $this->actingAs($this->admin())
            ->post(route('settings.users.store'), [
                'name' => 'Operador',
                'email' => 'operador@example.com',
                'password' => 'senha-super-segura',
                'password_confirmation' => 'senha-super-segura',
                'is_active' => true,
            ])
            ->assertSessionHas('success');

        $user = User::where('email', 'operador@example.com')->firstOrFail();
        $this->assertSame(0, $user->brands()->count());
        $this->assertFalse($user->is_admin);
    }

    public function test_can_create_platform_admin(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.users.store'), [
                'name' => 'Admin Novo',
                'email' => 'adminnovo@example.com',
                'password' => 'senha-super-segura',
                'password_confirmation' => 'senha-super-segura',
                'is_admin' => true,
            ]);

        $this->assertTrue(User::where('email', 'adminnovo@example.com')->firstOrFail()->is_admin);
    }

    public function test_admin_cannot_remove_own_admin_via_update(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('settings.users.update', $admin->id), [
                'name' => $admin->name,
                'email' => $admin->email,
                'is_admin' => false,
            ]);

        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('settings.users.store'), [
                'name' => 'X',
                'email' => 'x@example.com',
                'password' => 'senha-super-segura',
                'password_confirmation' => 'senha-super-segura',
            ])
            ->assertForbidden();
    }
}

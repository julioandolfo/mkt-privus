<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnboardingWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_cnpj_lookup_returns_company_data(): void
    {
        Http::fake([
            'brasilapi.com.br/*' => Http::response([
                'razao_social' => 'AZZO DISTRIBUIDORA LTDA',
                'nome_fantasia' => 'Azzo',
                'cnae_fiscal_descricao' => 'Comércio varejista',
                'ddd_telefone_1' => '11999990000',
                'cep' => '01310100',
                'logradouro' => 'Avenida Paulista',
                'numero' => '1000',
                'bairro' => 'Bela Vista',
                'municipio' => 'São Paulo',
                'uf' => 'SP',
            ]),
        ]);

        $this->getJson('/onboarding/cnpj/19131243000197')
            ->assertOk()
            ->assertJson([
                'legal_name' => 'AZZO DISTRIBUIDORA LTDA',
                'trade_name' => 'Azzo',
                'address_city' => 'São Paulo',
                'address_state' => 'SP',
            ]);
    }

    public function test_cnpj_lookup_validates_length(): void
    {
        $this->getJson('/onboarding/cnpj/123')->assertStatus(422);
    }

    public function test_cep_lookup_returns_address(): void
    {
        Http::fake([
            'viacep.com.br/*' => Http::response([
                'logradouro' => 'Avenida Paulista',
                'bairro' => 'Bela Vista',
                'localidade' => 'São Paulo',
                'uf' => 'SP',
            ]),
        ]);

        $this->getJson('/onboarding/cep/01310100')
            ->assertOk()
            ->assertJson(['address_city' => 'São Paulo', 'address_state' => 'SP']);
    }

    public function test_cep_not_found(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response(['erro' => true])]);

        $this->getJson('/onboarding/cep/00000000')->assertStatus(404);
    }

    public function test_registration_persists_full_company_and_onboarding_data(): void
    {
        $response = $this->post('/register', [
            'name' => 'Júlio',
            'email' => 'julio@azzo.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'brand_name' => 'Azzo Distribuidora',
            'cnpj' => '19.131.243/0001-97',
            'legal_name' => 'AZZO DISTRIBUIDORA LTDA',
            'phone' => '(11) 99999-0000',
            'company_size' => 'Média',
            'segment' => 'E-commerce',
            'cep' => '01310-100',
            'address_street' => 'Avenida Paulista',
            'address_number' => '1000',
            'address_neighborhood' => 'Bela Vista',
            'address_city' => 'São Paulo',
            'address_state' => 'SP',
            'goals' => ['Aumentar vendas', 'E-mail marketing'],
            'objective' => 'Profissionalizar o marketing digital.',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'julio@azzo.com')->firstOrFail();
        $brand = $user->brands()->first();

        $this->assertSame('Azzo Distribuidora', $brand->name);
        $this->assertSame('E-commerce', $brand->segment);
        $this->assertSame('Média', $brand->company_size);
        $this->assertSame('SP', $brand->address_state);
        $this->assertSame(['Aumentar vendas', 'E-mail marketing'], $brand->goals);
        $this->assertSame('Profissionalizar o marketing digital.', $brand->objective);
    }

    public function test_brand_name_is_required(): void
    {
        $this->post('/register', [
            'name' => 'Sem Empresa',
            'email' => 'sem@empresa.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('brand_name');
    }

    public function test_admin_insights_aggregates_by_segment_and_goals(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Brand::factory()->create(['segment' => 'E-commerce', 'goals' => ['Aumentar vendas', 'E-mail marketing']]);
        Brand::factory()->create(['segment' => 'E-commerce', 'goals' => ['Aumentar vendas']]);
        Brand::factory()->create(['segment' => 'Serviços', 'goals' => ['Gerar leads']]);

        $this->actingAs($admin)
            ->get(route('admin.insights'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Insights')
                ->where('bySegment.0.label', 'E-commerce')
                ->where('bySegment.0.count', 2)
                ->where('byGoal.0.label', 'Aumentar vendas')
                ->where('byGoal.0.count', 2));
    }

    public function test_non_admin_cannot_see_insights(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.insights'))->assertForbidden();
    }
}

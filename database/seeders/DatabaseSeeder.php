<?php

namespace Database\Seeders;

use App\Enums\BrandRole;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Criar usuario administrador
        $admin = User::create([
            'name' => 'Administrador',
            'email' => 'admin@mktprivus.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        // Criar marcas de exemplo
        $brands = [
            [
                'name' => 'Marca Principal',
                'slug' => 'marca-principal',
                'description' => 'Nossa marca principal de operações.',
                'segment' => 'Tecnologia',
                'target_audience' => 'Profissionais de tecnologia e inovação, 25-45 anos',
                'tone_of_voice' => 'profissional',
                'primary_color' => '#6366F1',
                'secondary_color' => '#8B5CF6',
                'accent_color' => '#F59E0B',
                'keywords' => ['tecnologia', 'inovação', 'digital', 'soluções'],
            ],
            [
                'name' => 'E-commerce Store',
                'slug' => 'ecommerce-store',
                'description' => 'Loja virtual de produtos.',
                'segment' => 'E-commerce',
                'target_audience' => 'Consumidores online, 18-55 anos',
                'tone_of_voice' => 'descontraido',
                'primary_color' => '#10B981',
                'secondary_color' => '#059669',
                'accent_color' => '#F43F5E',
                'keywords' => ['ofertas', 'promoção', 'compras', 'qualidade'],
            ],
        ];

        foreach ($brands as $brandData) {
            $brand = Brand::create($brandData);
            $brand->users()->attach($admin->id, [
                'role' => BrandRole::Owner->value,
            ]);
        }

        // Definir primeira marca como ativa
        $admin->update(['current_brand_id' => Brand::first()->id]);
    }
}

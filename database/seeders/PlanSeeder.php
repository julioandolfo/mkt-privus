<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'Para quem está começando a automatizar o marketing.',
                'price' => 97.00,
                'sort_order' => 1,
                'features' => [
                    '1 marca',
                    '2 usuários',
                    '10.000 e-mails/mês',
                    '1.000 SMS/mês',
                    '500 mil tokens de IA/mês',
                    'Posts e blog ilimitados',
                ],
                'limits' => [
                    'max_brands' => 1,
                    'max_users' => 2,
                    'monthly_emails' => 10000,
                    'monthly_sms' => 1000,
                    'monthly_ai_tokens' => 500000,
                ],
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'Para equipes e agências em crescimento.',
                'price' => 197.00,
                'sort_order' => 2,
                'features' => [
                    '3 marcas',
                    '5 usuários',
                    '50.000 e-mails/mês',
                    '5.000 SMS/mês',
                    '2 milhões de tokens de IA/mês',
                    'Posts e blog ilimitados',
                ],
                'limits' => [
                    'max_brands' => 3,
                    'max_users' => 5,
                    'monthly_emails' => 50000,
                    'monthly_sms' => 5000,
                    'monthly_ai_tokens' => 2000000,
                ],
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'Para operações com múltiplas marcas e alto volume.',
                'price' => 497.00,
                'sort_order' => 3,
                'features' => [
                    '10 marcas',
                    '15 usuários',
                    '200.000 e-mails/mês',
                    '20.000 SMS/mês',
                    '10 milhões de tokens de IA/mês',
                    'Posts e blog ilimitados',
                ],
                'limits' => [
                    'max_brands' => 10,
                    'max_users' => 15,
                    'monthly_emails' => 200000,
                    'monthly_sms' => 20000,
                    'monthly_ai_tokens' => 10000000,
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}

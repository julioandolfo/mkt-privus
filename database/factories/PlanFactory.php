<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'price' => 97.00,
            'currency' => 'BRL',
            'interval' => 'month',
            'limits' => [
                'max_brands' => 1,
                'max_users' => 2,
                'monthly_emails' => 10000,
                'monthly_ai_tokens' => 500000,
            ],
            'is_active' => true,
            'sort_order' => 1,
        ];
    }
}

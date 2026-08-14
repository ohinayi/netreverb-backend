<?php

namespace Database\Factories;

use App\Models\PricingGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricingGroup>
 */
class PricingGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'price_minor' => fake()->numberBetween(0, 20000),
            'currency' => 'USD',
            'billing_interval' => 'monthly',
            'features' => ['sip_calling'],
            'is_active' => true,
        ];
    }
}

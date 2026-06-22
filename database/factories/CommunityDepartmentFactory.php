<?php

namespace Database\Factories;

use App\Models\Community;
use App\Models\CommunityDepartment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommunityDepartment>
 */
class CommunityDepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'community_id' => Community::factory(),
            'name' => fake()->jobTitle(),
            'slug' => Str::slug(fake()->unique()->jobTitle().'-'.fake()->numberBetween(1, 9999)),
            'description' => fake()->optional()->sentence(),
            'color' => fake()->hexColor(),
        ];
    }
}

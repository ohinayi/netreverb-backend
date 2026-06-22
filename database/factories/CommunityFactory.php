<?php

namespace Database\Factories;

use App\Enums\CommunityVisibility;
use App\Models\Community;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Community>
 */
class CommunityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'name' => fake()->company(),
            'slug' => Str::slug(fake()->unique()->company().'-'.fake()->unique()->numberBetween(1, 9999)),
            'description' => fake()->optional()->sentence(),
            'visibility' => CommunityVisibility::Private,
            'status' => 'active',
            'settings' => null,
            'archived_at' => null,
        ];
    }
}

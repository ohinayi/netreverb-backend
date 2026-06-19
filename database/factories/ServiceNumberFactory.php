<?php

namespace Database\Factories;

use App\Enums\DialableNumberType;
use App\Enums\ServiceNumberType;
use App\Models\DialableNumber;
use App\Models\Organization;
use App\Models\ServiceNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceNumber>
 */
class ServiceNumberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'dialable_number_id' => fn (array $attributes): int => DialableNumber::factory()->service()->create([
                'organization_id' => $attributes['organization_id'],
                'type' => DialableNumberType::Service,
            ])->id,
            'name' => fake()->words(2, true),
            'type' => ServiceNumberType::Custom,
            'target' => fake()->numerify('####'),
            'enabled' => true,
            'configuration' => null,
        ];
    }
}

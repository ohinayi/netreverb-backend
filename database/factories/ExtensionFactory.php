<?php

namespace Database\Factories;

use App\Enums\DialableNumberType;
use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Models\DialableNumber;
use App\Models\Extension;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Extension>
 */
class ExtensionFactory extends Factory
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
            'dialable_number_id' => fn (array $attributes): int => DialableNumber::factory()->create([
                'organization_id' => $attributes['organization_id'],
                'type' => DialableNumberType::Extension,
            ])->id,
            'user_id' => null,
            'display_name' => fake()->name(),
            'type' => ExtensionType::User,
            'status' => ExtensionStatus::Active,
        ];
    }
}

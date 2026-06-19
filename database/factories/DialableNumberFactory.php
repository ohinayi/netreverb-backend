<?php

namespace Database\Factories;

use App\Enums\DialableNumberType;
use App\Models\DialableNumber;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DialableNumber>
 */
class DialableNumberFactory extends Factory
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
            'realm' => config('telephony.sip_realm'),
            'number' => fake()->unique()->numerify('2#####'),
            'type' => DialableNumberType::Extension,
        ];
    }

    public function service(): static
    {
        return $this->state(fn (): array => ['type' => DialableNumberType::Service]);
    }
}

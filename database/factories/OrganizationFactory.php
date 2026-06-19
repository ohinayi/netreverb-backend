<?php

namespace Database\Factories;

use App\Enums\ExtensionProvisioningMode;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(3),
            'status' => OrganizationStatus::Active,
            'extension_provisioning_mode' => ExtensionProvisioningMode::Manual,
            'timezone' => 'UTC',
            'locale' => 'en',
            'settings' => null,
        ];
    }
}

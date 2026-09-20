<?php

namespace Database\Factories;

use App\Models\Extension;
use App\Models\Organization;
use App\Models\Voicemail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Voicemail>
 */
class VoicemailFactory extends Factory
{
    protected $model = Voicemail::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'extension_id' => Extension::factory(),
            'caller_number' => $this->faker->numerify('2#########'),
            'file_path' => 'voicemail/'.$this->faker->uuid().'.wav',
            'duration_seconds' => $this->faker->numberBetween(3, 90),
            'listened_at' => null,
        ];
    }
}

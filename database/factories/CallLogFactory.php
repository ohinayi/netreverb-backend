<?php

namespace Database\Factories;

use App\Enums\CallMediaType;
use App\Enums\CallRecordingMediaType;
use App\Enums\CallSessionType;
use App\Enums\CallStatus;
use App\Models\CallLog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallLog>
 */
class CallLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = now()->subSeconds(fake()->numberBetween(60, 3600));
        $duration = fake()->numberBetween(10, 600);

        return [
            'organization_id' => Organization::factory(),
            'caller_extension_id' => null,
            'callee_extension_id' => null,
            'caller_number' => fake()->e164PhoneNumber(),
            'callee_number' => fake()->e164PhoneNumber(),
            'status' => CallStatus::Completed,
            'media_type' => CallMediaType::Audio,
            'session_type' => CallSessionType::Direct,
            'duration' => $duration,
            'freeswitch_uuid' => null,
            'recording_url' => null,
            'recording_id' => null,
            'recording_uuid' => null,
            'recording_media_type' => null,
            'recording_container' => null,
            'recording_file_path' => null,
            'recording_file_name' => null,
            'recording_duration' => null,
            'recording_size' => null,
            'recording_status' => null,
            'recording_started_at' => null,
            'recording_ended_at' => null,
            'started_at' => $started,
            'ended_at' => $started->copy()->addSeconds($duration),
        ];
    }

    /**
     * State for a call with recording.
     */
    public function withRecording(): static
    {
        return $this->state(fn (array $attributes): array => [
            'recording_url' => 'https://storage.netreverb.com/recordings/'.fake()->uuid().'.mp3',
            'recording_media_type' => CallRecordingMediaType::Audio,
            'recording_container' => 'mp3',
            'recording_duration' => $attributes['duration'],
            'recording_size' => fake()->numberBetween(100000, 5000000),
        ]);
    }
}

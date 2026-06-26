<?php

namespace Database\Factories;

use App\Enums\ConferenceRecordingStatus;
use App\Models\ConferenceRecording;
use App\Models\ConferenceRoom;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConferenceRecording>
 */
class ConferenceRecordingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fileName = (string) Str::ulid().'.wav';

        return [
            'conference_room_id' => ConferenceRoom::factory(),
            'recording_id' => (string) Str::ulid(),
            'room_id' => (string) Str::ulid(),
            'call_id' => (string) Str::ulid(),
            'file_path' => now()->format('Y/m/d').'/'.$fileName,
            'file_name' => $fileName,
            'duration' => null,
            'size' => null,
            'status' => ConferenceRecordingStatus::Recording,
        ];
    }
}

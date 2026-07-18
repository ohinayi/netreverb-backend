<?php

namespace Database\Factories;

use App\Enums\CallRecordingMediaType;
use App\Enums\CallRecordingUploadStatus;
use App\Models\CallLog;
use App\Models\CallRecordingUpload;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CallRecordingUpload>
 */
class CallRecordingUploadFactory extends Factory
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
            'call_log_id' => CallLog::factory(),
            'recording_id' => (string) Str::ulid(),
            'status' => CallRecordingUploadStatus::Pending,
            'media_type' => CallRecordingMediaType::Video,
            'container' => 'webm',
            'mime_type' => 'video/webm',
            'file_path' => '2026/07/14/'.Str::lower(fake()->bothify('recording-######')).'.webm',
            'file_name' => Str::lower(fake()->bothify('recording-######')).'.webm',
            'next_sequence' => 0,
            'uploaded_chunks_count' => 0,
            'uploaded_size' => 0,
            'upload_started_at' => null,
            'last_chunk_received_at' => null,
            'upload_completed_at' => null,
            'finalized_at' => null,
        ];
    }
}

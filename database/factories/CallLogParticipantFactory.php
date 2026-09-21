<?php

namespace Database\Factories;

use App\Enums\CallLogParticipantStatus;
use App\Models\CallLog;
use App\Models\CallLogParticipant;
use App\Models\Extension;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallLogParticipant>
 */
class CallLogParticipantFactory extends Factory
{
    protected $model = CallLogParticipant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'call_log_id' => CallLog::factory(),
            'extension_id' => Extension::factory(),
            'added_by_user_id' => User::factory(),
            'freeswitch_uuid' => $this->faker->uuid(),
            'status' => CallLogParticipantStatus::Active,
            'joined_at' => now(),
            'left_at' => null,
        ];
    }
}

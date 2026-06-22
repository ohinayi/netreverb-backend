<?php

namespace Database\Factories;

use App\Enums\CommunityMembershipRole;
use App\Enums\CommunityMembershipStatus;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityMembership>
 */
class CommunityMembershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'community_id' => Community::factory(),
            'user_id' => User::factory(),
            'community_department_id' => null,
            'invited_by_user_id' => null,
            'role' => CommunityMembershipRole::Member,
            'status' => CommunityMembershipStatus::Active,
            'joined_at' => now(),
            'left_at' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\ConversationKind;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'community_id' => null,
            'created_by_user_id' => User::factory(),
            'kind' => ConversationKind::Group,
            'title' => fake()->sentence(3),
            'direct_key' => null,
            'last_message_at' => now(),
            'archived_at' => null,
        ];
    }
}

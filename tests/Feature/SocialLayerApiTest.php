<?php

namespace Tests\Feature;

use App\Enums\CommunityMembershipRole;
use App\Enums\CommunityMembershipStatus;
use App\Enums\ConversationKind;
use App\Enums\FriendshipStatus;
use App\Models\Community;
use App\Models\CommunityDepartment;
use App\Models\CommunityMembership;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SocialLayerApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_can_search_people_and_send_a_friend_request(): void
    {
        $sender = User::factory()->create(['email_verified_at' => now()]);
        $receiver = User::factory()->create(['name' => 'Grace Hopper', 'email_verified_at' => now()]);
        Sanctum::actingAs($sender);

        $this->getJson('/api/v1/people/search?q=Grace')
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $receiver->public_id)
            ->assertJsonPath('data.0.name', 'Grace Hopper');

        $response = $this->postJson('/api/v1/friendships', [
            'addressee_public_id' => $receiver->public_id,
            'note' => 'Let us connect.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', FriendshipStatus::Pending->value);

        $friendship = Friendship::query()->sole();

        Sanctum::actingAs($receiver);

        $this->postJson("/api/v1/friendships/{$friendship->public_id}/respond", [
            'decision' => FriendshipStatus::Accepted->value,
        ])->assertOk()->assertJsonPath('data.status', FriendshipStatus::Accepted->value);
    }

    public function test_owner_can_create_a_community_and_assign_a_department(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $member = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($owner);

        $communityResponse = $this->postJson('/api/v1/communities', [
            'name' => 'Care Team',
            'visibility' => 'invite_only',
        ]);

        $communityResponse->assertCreated()
            ->assertJsonPath('data.name', 'Care Team')
            ->assertJsonPath('data.member_count', 1);

        $community = Community::query()->sole();

        $departmentResponse = $this->postJson("/api/v1/communities/{$community->public_id}/departments", [
            'name' => 'Nurses',
        ]);

        $departmentResponse->assertCreated()
            ->assertJsonPath('data.name', 'Nurses');

        $department = CommunityDepartment::query()->sole();

        $inviteResponse = $this->postJson("/api/v1/communities/{$community->public_id}/invite", [
            'user_public_id' => $member->public_id,
            'community_department_public_id' => $department->public_id,
            'role' => CommunityMembershipRole::Member->value,
        ]);

        $inviteResponse->assertSuccessful()
            ->assertJsonPath('data.status', CommunityMembershipStatus::Invited->value);

        $this->assertSame(
            CommunityMembershipStatus::Invited,
            CommunityMembership::query()->where('user_id', $member->id)->sole()->status,
        );

        $this->postJson("/api/v1/communities/{$community->public_id}/members/{$member->public_id}/department", [
            'community_department_public_id' => $department->public_id,
        ])->assertSuccessful();

        $membership = CommunityMembership::query()->where('user_id', $member->id)->sole();

        $this->assertSame($department->id, $membership->community_department_id);
    }

    public function test_user_can_create_a_direct_conversation_and_send_messages(): void
    {
        $sender = User::factory()->create(['email_verified_at' => now()]);
        $recipient = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($sender);

        $conversationResponse = $this->postJson('/api/v1/conversations', [
            'kind' => ConversationKind::Direct->value,
            'participant_public_ids' => [$recipient->public_id],
        ]);

        $conversationResponse->assertCreated()
            ->assertJsonPath('data.kind', ConversationKind::Direct->value);

        $conversation = Conversation::query()->sole();

        $messageResponse = $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", [
            'body' => 'Hello there',
            'type' => 'text',
        ]);

        $messageResponse->assertCreated()
            ->assertJsonPath('data.body', 'Hello there');

        $this->assertSame(1, Message::query()->count());
    }
}

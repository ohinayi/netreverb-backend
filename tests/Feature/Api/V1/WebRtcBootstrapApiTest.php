<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipRole;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebRtcBootstrapApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_assigned_user_receives_sip_and_short_lived_turn_credentials(): void
    {
        Carbon::setTestNow('2026-06-20 12:00:00');
        config()->set([
            'telephony.turn.secret' => 'test-turn-shared-secret',
            'telephony.turn.ttl' => 600,
        ]);
        [$user, $organization, $extension] = $this->assignedExtension();
        Sanctum::actingAs($user);

        $expiresAt = now()->addMinutes(10)->timestamp;
        $turnUsername = $expiresAt.':'.$user->public_id;
        $turnCredential = base64_encode(hash_hmac(
            'sha1',
            $turnUsername,
            'test-turn-shared-secret',
            true,
        ));

        $this->getJson('/api/v1/webrtc/bootstrap')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'wss' => 'wss://sip.classyra.com.ng:7443',
                'sip' => [
                    'username' => $extension->dialableNumber->number,
                    'password' => 'sip-secret',
                    'realm' => 'sip.classyra.com.ng',
                    'expires' => 300,
                ],
                'iceServers' => [
                    [
                        'urls' => [
                            'stun:sip.classyra.com.ng:3478',
                            'stun:stun.l.google.com:19302',
                        ],
                    ],
                    [
                        'urls' => [
                            'turn:sip.classyra.com.ng:3478?transport=udp',
                            'turns:sip.classyra.com.ng:5349?transport=tcp',
                        ],
                        'username' => $turnUsername,
                        'credential' => $turnCredential,
                    ],
                ],
                'expires_at' => $expiresAt,
            ]);
    }

    public function test_user_must_select_an_extension_when_multiple_are_assigned(): void
    {
        config()->set('telephony.turn.secret', 'test-turn-shared-secret');
        [$user, $organization, $firstExtension] = $this->assignedExtension();
        $secondExtension = Extension::factory()->for($organization)->for($user)->create();
        $secondExtension->credential()->create(['password' => 'second-sip-secret']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/webrtc/bootstrap')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('extension_id');

        $this->getJson('/api/v1/webrtc/bootstrap?extension_id='.$secondExtension->public_id)
            ->assertOk()
            ->assertJsonPath('sip.username', $secondExtension->dialableNumber->number)
            ->assertJsonPath('sip.password', 'second-sip-secret');
    }

    public function test_user_cannot_bootstrap_another_users_extension(): void
    {
        config()->set('telephony.turn.secret', 'test-turn-shared-secret');
        [$user] = $this->assignedExtension();
        [, , $otherExtension] = $this->assignedExtension();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/webrtc/bootstrap?extension_id='.$otherExtension->public_id)
            ->assertNotFound();
    }

    public function test_bootstrap_requires_authentication_and_verified_email(): void
    {
        $this->getJson('/api/v1/webrtc/bootstrap')->assertUnauthorized();

        $unverifiedUser = User::factory()->unverified()->create();
        Sanctum::actingAs($unverifiedUser);

        $this->getJson('/api/v1/webrtc/bootstrap')->assertForbidden();
    }

    public function test_bootstrap_is_rate_limited(): void
    {
        config()->set('telephony.turn.secret', 'test-turn-shared-secret');
        $rateLimitUser = User::factory()->create(['id' => 999999]);
        [$user] = $this->assignedExtension($rateLimitUser);
        Sanctum::actingAs($user);

        foreach (range(1, 10) as $attempt) {
            $this->getJson('/api/v1/webrtc/bootstrap')->assertOk();
        }

        $this->getJson('/api/v1/webrtc/bootstrap')->assertTooManyRequests();
    }

    /** @return array{User, Organization, Extension} */
    private function assignedExtension(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($user)->create([
            'role' => MembershipRole::Member,
        ]);
        $extension = Extension::factory()->for($organization)->for($user)->create();
        $extension->credential()->create(['password' => 'sip-secret']);

        return [$user, $organization, $extension];
    }
}

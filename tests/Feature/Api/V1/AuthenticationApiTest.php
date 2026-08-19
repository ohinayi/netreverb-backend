<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ProvisionSipSubscriber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Browser authentication is session-cookie based. Mark these requests
        // as first-party so Sanctum installs the session middleware stack.
        $this->withHeader('Origin', 'http://localhost:5174');
    }

    public function test_registration_creates_an_unverified_user_and_individual_workspace(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'person@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonMissingPath('token');

        $user = User::query()->sole();
        $organization = Organization::query()->sole();

        $this->assertNull($user->email_verified_at);
        $this->assertSame('NG', $user->country_code);
        $this->assertSame('individual', $organization->settings['kind']);
        $this->assertSame($user->id, OrganizationMembership::query()->sole()->user_id);
        $this->assertDatabaseCount((new Extension)->getTable(), 0);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_community_registration_creates_a_community_workspace_shell(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', array_merge(
            $this->registrationPayload(),
            [
                'email' => 'community@example.com',
                'account_type' => 'community',
                'workspace_name' => 'North Clinic',
            ],
        ));

        $response->assertCreated()
            ->assertJsonPath('data.account_type', 'community');

        $organization = Organization::query()->sole();

        $this->assertSame('community', $organization->settings['kind']);
        $this->assertSame('North Clinic', $organization->name);
        $this->assertSame('community', User::query()->sole()->account_type->value);
    }

    public function test_unverified_user_cannot_access_tenant_apis(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', $this->registrationPayload());
        $user = User::query()->sole();

        $this->actingAs($user)->getJson('/api/v1/organizations')->assertForbidden();
    }

    public function test_signed_verification_creates_exactly_one_automatic_extension(): void
    {
        Notification::fake();
        Queue::fake();
        $this->postJson('/api/v1/auth/register', $this->registrationPayload());
        $user = User::query()->sole();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
        );

        $this->getJson($verificationUrl)->assertOk();
        $this->getJson($verificationUrl)->assertOk();

        $extension = Extension::query()->with(['dialableNumber', 'organization'])->sole();
        $this->assertTrue($user->refresh()->hasVerifiedEmail());
        $this->assertSame('100000', $extension->dialableNumber->number);
        $this->assertSame($user->id, $extension->user_id);
        Queue::assertPushed(ProvisionSipSubscriber::class, 1);
    }

    public function test_signed_verification_creates_an_automatic_extension_for_a_community_owner(): void
    {
        Notification::fake();
        Queue::fake();

        $this->postJson('/api/v1/auth/register', array_merge($this->registrationPayload(), [
            'account_type' => 'community',
            'workspace_name' => 'North Clinic',
            'assign_extension' => true,
        ]));
        $user = User::query()->sole();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
        );

        $this->getJson($verificationUrl)->assertOk();

        $extension = Extension::query()->with(['dialableNumber', 'organization'])->sole();
        $this->assertSame($user->id, $extension->user_id);
        $this->assertSame('North Clinic', $extension->organization->name);
        Queue::assertPushed(ProvisionSipSubscriber::class, 1);
    }

    public function test_verification_email_opens_the_frontend_with_a_signed_backend_url(): void
    {
        $user = User::factory()->unverified()->create();
        $mail = (new VerifyEmailNotification)->toMail($user);

        $this->assertStringStartsWith(
            'http://localhost:5174/auth/verify-email?',
            $mail->actionUrl,
        );

        parse_str((string) parse_url($mail->actionUrl, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('verification_url', $query);
        $this->assertStringStartsWith(
            'http://localhost:8000/api/v1/email/verify/',
            $query['verification_url'],
        );
        $this->assertTrue(URL::hasValidSignature(Request::create($query['verification_url'])));
    }

    public function test_invalid_login_uses_a_generic_error_and_valid_login_starts_a_browser_session(): void
    {
        $user = User::factory()->create(['email' => 'person@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'browser',
        ])->assertOk()->assertJsonPath('data.email', $user->email)->assertJsonMissingPath('token');
    }

    public function test_logout_ends_the_current_browser_session(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'browser',
        ])->assertOk();

        $this->deleteJson('/api/v1/auth/logout')->assertNoContent();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_google_oauth_updates_an_existing_users_name_from_the_provider_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Sulaimon John',
            'email' => 'person@example.com',
            'email_verified_at' => now(),
        ]);

        $googleUser = $this->fakeGoogleUser([
            'id' => 'google-123',
            'name' => 'Grace Hopper',
            'email' => $user->email,
            'avatar' => 'https://example.com/grace.jpg',
        ]);

        $this->mockGoogleDriver($googleUser);

        $this->withSession(['oauth.redirect' => '/app/home'])
            ->get('/auth/oauth/google/callback')
            ->assertRedirect('http://localhost:5174/auth/oauth/callback?provider=google&status=success&redirect=%2Fapp%2Fhome');

        $this->assertSame('Grace Hopper', $user->refresh()->name);
    }

    public function test_google_oauth_updates_an_linked_users_name_from_the_provider_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Sulaimon John',
            'email' => 'person@example.com',
            'email_verified_at' => now(),
        ]);
        $user->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => 'google-123',
            'avatar_url' => null,
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
        ]);

        $googleUser = $this->fakeGoogleUser([
            'id' => 'google-123',
            'name' => 'Grace Hopper',
            'email' => $user->email,
            'avatar' => 'https://example.com/grace.jpg',
        ]);

        $this->mockGoogleDriver($googleUser);

        $this->withSession(['oauth.redirect' => '/app/home'])
            ->get('/auth/oauth/google/callback')
            ->assertRedirect('http://localhost:5174/auth/oauth/callback?provider=google&status=success&redirect=%2Fapp%2Fhome');

        $this->assertSame('Grace Hopper', $user->refresh()->name);
    }

    public function test_a_brand_new_google_signup_has_no_organization_until_they_complete_setup(): void
    {
        $googleUser = $this->fakeGoogleUser([
            'id' => 'google-999',
            'name' => 'New Googler',
            'email' => 'new-googler@example.com',
            'avatar' => 'https://example.com/new.jpg',
        ]);
        $this->mockGoogleDriver($googleUser);

        $this->get('/auth/oauth/google/callback')->assertRedirect();

        $user = User::query()->sole();
        $this->assertDatabaseCount((new Organization)->getTable(), 0);
        $this->assertDatabaseCount((new Extension)->getTable(), 0);

        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.has_organization', false);
    }

    public function test_completing_organization_creates_an_individual_workspace_and_extension(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson('/api/v1/auth/organization', [
            'account_type' => 'individual',
            'terms_accepted' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.has_organization', true)
            ->assertJsonPath('data.account_type', 'individual');

        $organization = Organization::query()->sole();
        $this->assertSame('individual', $organization->settings['kind']);
        $this->assertSame($user->id, OrganizationMembership::query()->sole()->user_id);
        $extension = Extension::query()->sole();
        $this->assertSame($user->id, $extension->user_id);
    }

    public function test_completing_organization_creates_a_community_workspace_without_auto_extension(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson('/api/v1/auth/organization', [
            'account_type' => 'community',
            'workspace_name' => 'Acme Corp',
            'terms_accepted' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.has_organization', true);

        $organization = Organization::query()->sole();
        $this->assertSame('Acme Corp', $organization->name);
        $this->assertSame('community', $organization->settings['kind']);
        $this->assertDatabaseCount((new Extension)->getTable(), 0);
    }

    public function test_completing_organization_requires_a_workspace_name_for_community_accounts(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson('/api/v1/auth/organization', [
            'account_type' => 'community',
            'terms_accepted' => true,
        ])->assertJsonValidationErrors(['workspace_name']);
    }

    public function test_completing_organization_twice_returns_conflict_without_creating_a_second_workspace(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson('/api/v1/auth/organization', [
            'account_type' => 'individual',
            'terms_accepted' => true,
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/v1/auth/organization', [
            'account_type' => 'individual',
            'terms_accepted' => true,
        ])->assertStatus(409);

        $this->assertDatabaseCount((new Organization)->getTable(), 1);
    }

    public function test_completing_organization_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/organization', [
            'account_type' => 'individual',
            'terms_accepted' => true,
        ])->assertUnauthorized();
    }

    /** @return array<string, mixed> */
    private function registrationPayload(): array
    {
        return [
            'name' => 'Example Person',
            'email' => 'PERSON@example.com',
            'password' => 'Strong!Password123',
            'password_confirmation' => 'Strong!Password123',
            'country_code' => 'ng',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
            'account_type' => 'individual',
            'terms_accepted' => true,
            'device_name' => 'browser',
        ];
    }

    /** @param array{id: string, name: string, email: string, avatar: string} $attributes */
    private function fakeGoogleUser(array $attributes): \Laravel\Socialite\Contracts\User
    {
        return \Laravel\Socialite\Two\User::fake([
            'id' => $attributes['id'],
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'avatar' => $attributes['avatar'],
            'nickname' => null,
            'user' => [
                'email_verified' => true,
            ],
            'token' => 'fake-token',
            'refreshToken' => 'fake-refresh-token',
            'expiresIn' => 3600,
        ]);
    }

    private function mockGoogleDriver(\Laravel\Socialite\Contracts\User $googleUser): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('user')
            ->once()
            ->andReturn($googleUser);

        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturn($driver);
    }
}

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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registration_creates_an_unverified_user_and_personal_workspace(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'person@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['token']);

        $user = User::query()->sole();
        $organization = Organization::query()->sole();

        $this->assertNull($user->email_verified_at);
        $this->assertSame('NG', $user->country_code);
        $this->assertSame('personal', $organization->settings['kind']);
        $this->assertSame($user->id, OrganizationMembership::query()->sole()->user_id);
        $this->assertDatabaseCount((new Extension)->getTable(), 0);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_unverified_user_cannot_access_tenant_apis(): void
    {
        Notification::fake();
        $token = $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->json('token');

        $this->withToken($token)->getJson('/api/v1/organizations')->assertForbidden();
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

        $extension = Extension::query()->with('dialableNumber')->sole();
        $this->assertTrue($user->refresh()->hasVerifiedEmail());
        $this->assertSame('100000', $extension->dialableNumber->number);
        $this->assertSame($user->id, $extension->user_id);
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

    public function test_invalid_login_uses_a_generic_error_and_valid_login_returns_a_token(): void
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
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('browser')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
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
            'terms_accepted' => true,
            'device_name' => 'browser',
        ];
    }
}

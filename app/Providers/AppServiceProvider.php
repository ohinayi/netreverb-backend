<?php

namespace App\Providers;

use App\Contracts\Ai\AudioTranscriptionProvider;
use App\Contracts\Ai\TimestampedAudioTranscriptionProvider;
use App\Contracts\Ai\StructuredAssistantProvider;
use App\Contracts\Messaging\OutboundMessageProvider;
use App\Contracts\Recordings\CallRecordingStorage;
use App\Contracts\Recordings\ConferenceRecordingStorage;
use App\Contracts\Telephony\FreeSwitchCallGateway;
use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Contracts\Telephony\FreeSwitchQueueGateway;
use App\Contracts\Telephony\SipSubscriberGateway;
use App\Contracts\Translation\MessageTranslationProvider;
use App\Models\CallLog;
use App\Models\User;
use App\Observers\CallLogObserver;
use App\Observers\UserObserver;
use App\Services\Ai\GeminiStructuredAssistantProvider;
use App\Services\Ai\WhisperCppTranscriptionProvider;
use App\Services\Ai\WhisperCppTimestampedAudioTranscriptionProvider;
use App\Services\Messaging\DisabledOutboundMessageProvider;
use App\Services\Messaging\EBulkSmsOutboundMessageProvider;
use App\Services\Recordings\LocalCallRecordingStorage;
use App\Services\Recordings\LocalConferenceRecordingStorage;
use App\Services\Telephony\DatabaseSipSubscriberGateway;
use App\Services\Telephony\FreeSwitchEventSocketClient;
use App\Services\Telephony\SocketFreeSwitchCallGateway;
use App\Services\Telephony\SocketFreeSwitchConferenceGateway;
use App\Services\Telephony\SocketFreeSwitchQueueGateway;
use App\Services\Translation\LibreTranslateMessageTranslationProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AudioTranscriptionProvider::class, WhisperCppTranscriptionProvider::class);
        $this->app->bind(TimestampedAudioTranscriptionProvider::class, WhisperCppTimestampedAudioTranscriptionProvider::class);
        $this->app->bind(StructuredAssistantProvider::class, GeminiStructuredAssistantProvider::class);
        $this->app->bind(
            OutboundMessageProvider::class,
            fn () => match (config('outbound.provider')) {
                'ebulksms' => app(EBulkSmsOutboundMessageProvider::class),
                default => app(DisabledOutboundMessageProvider::class),
            },
        );
        $this->app->bind(SipSubscriberGateway::class, DatabaseSipSubscriberGateway::class);
        $this->app->bind(ConferenceRecordingStorage::class, LocalConferenceRecordingStorage::class);
        $this->app->bind(CallRecordingStorage::class, LocalCallRecordingStorage::class);
        $this->app->bind(MessageTranslationProvider::class, function () {
            return match (config('translation.provider')) {
                'libretranslate' => app(LibreTranslateMessageTranslationProvider::class),
                default => app(LibreTranslateMessageTranslationProvider::class),
            };
        });
        $this->app->singleton(FreeSwitchEventSocketClient::class, function (): FreeSwitchEventSocketClient {
            return new FreeSwitchEventSocketClient(
                host: config('telephony.freeswitch.event_socket_host'),
                port: (int) config('telephony.freeswitch.event_socket_port'),
                password: (string) config('telephony.freeswitch.event_socket_password'),
                timeoutSeconds: (int) config('telephony.freeswitch.event_socket_timeout_seconds'),
            );
        });
        $this->app->bind(FreeSwitchConferenceGateway::class, SocketFreeSwitchConferenceGateway::class);
        $this->app->bind(FreeSwitchCallGateway::class, SocketFreeSwitchCallGateway::class);
        $this->app->bind(FreeSwitchQueueGateway::class, SocketFreeSwitchQueueGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! app()->isProduction());
        CallLog::observe(CallLogObserver::class);
        User::observe(UserObserver::class);

        RateLimiter::for('webrtc-bootstrap', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('sip-registration', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('auth-login', fn (Request $request): array => [
            Limit::perMinute(5)->by('login:'.$this->hashedEmail($request)),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('auth-registration', fn (Request $request): array => [
            Limit::perMinute(3)->by('registration:'.$this->hashedEmail($request)),
            Limit::perMinute(10)->by('registration-ip:'.$request->ip()),
        ]);

        RateLimiter::for('password-recovery', fn (Request $request): array => [
            Limit::perMinute(3)->by('password-recovery:'.$this->hashedEmail($request)),
            Limit::perMinute(10)->by('password-recovery-ip:'.$request->ip()),
        ]);

        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perMinute(5)->by('password-reset:'.$this->hashedEmail($request)),
            Limit::perMinute(20)->by('password-reset-ip:'.$request->ip()),
        ]);

        RateLimiter::for('oauth-redirect', fn (Request $request): array => [
            Limit::perMinute(10)->by('oauth-redirect:'.Str::lower((string) $request->route('provider')).':'.$request->ip()),
            Limit::perMinute(30)->by('oauth-redirect-ip:'.$request->ip()),
        ]);

        RateLimiter::for('oauth-callback', fn (Request $request): array => [
            Limit::perMinute(10)->by('oauth-callback:'.Str::lower((string) $request->route('provider')).':'.$request->ip()),
            Limit::perMinute(30)->by('oauth-callback-ip:'.$request->ip()),
        ]);

        RateLimiter::for('message-send', fn (Request $request): array => [
            Limit::perMinute(60)->by('message-user:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            Limit::perMinute(180)->by('message-ip:'.$request->ip()),
        ]);

        RateLimiter::for('message-translate', fn (Request $request): array => [
            Limit::perMinute(30)->by('message-translate-user:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            Limit::perMinute(90)->by('message-translate-ip:'.$request->ip()),
        ]);

        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            $verificationUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(60),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
            );

            return rtrim(config('app.frontend_url'), '/')
                .'/auth/verify-email?'
                .http_build_query(
                    ['verification_url' => $verificationUrl],
                    encoding_type: PHP_QUERY_RFC3986,
                );
        });

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            return rtrim(config('app.frontend_url'), '/')
                .'/auth/reset-password?'
                .http_build_query(
                    ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()],
                    encoding_type: PHP_QUERY_RFC3986,
                );
        });
    }

    private function hashedEmail(Request $request): string
    {
        return hash('sha256', Str::lower(trim($request->string('email')->toString())));
    }
}

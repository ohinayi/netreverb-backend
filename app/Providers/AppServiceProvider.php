<?php

namespace App\Providers;

use App\Contracts\Telephony\SipSubscriberGateway;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\Telephony\DatabaseSipSubscriberGateway;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SipSubscriberGateway::class, DatabaseSipSubscriberGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! app()->isProduction());
        User::observe(UserObserver::class);

        RateLimiter::for('webrtc-bootstrap', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('sip-registration', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

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
    }
}

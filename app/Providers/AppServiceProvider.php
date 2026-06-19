<?php

namespace App\Providers;

use App\Contracts\Telephony\SipSubscriberGateway;
use App\Services\Telephony\DatabaseSipSubscriberGateway;
use Illuminate\Database\Eloquent\Model;
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
    }
}

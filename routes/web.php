<?php

use App\Http\Controllers\Api\V1\Auth\OAuthController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('auth/oauth')->group(function (): void {
    Route::get('{provider}/redirect', [OAuthController::class, 'redirect'])
        ->middleware('throttle:oauth-redirect')
        ->name('auth.oauth.redirect');

    Route::get('{provider}/callback', [OAuthController::class, 'callback'])
        ->middleware('throttle:oauth-callback')
        ->name('auth.oauth.callback');
});

if (config('api_docs.enabled')) {
    Route::view('/docs/api', 'api-docs')->name('api-docs.ui');
    Route::get('/docs/api/openapi.yaml', fn () => response(
        File::get(base_path('docs/openapi.yaml')),
        headers: ['Content-Type' => 'application/yaml'],
    ))->name('api-docs.spec');
}

<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
use App\Http\Controllers\Api\V1\ConferenceRoomController;
use App\Http\Controllers\Api\V1\ExtensionController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ServiceNumberController;
use App\Http\Controllers\Api\V1\SipCredentialController;
use App\Http\Controllers\Api\V1\SipRegistrationController;
use App\Http\Controllers\Api\V1\WebRtcBootstrapController;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/register', RegisteredUserController::class)->middleware('throttle:5,1');
    Route::post('auth/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:5,1');
    Route::get('email/verify/{id}/{hash}', EmailVerificationController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::get('email/verify-required', fn () => response()->json([
        'message' => 'Email verification is required.',
    ], 403))->name('verification.notice');

    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
        Route::get('/me', fn (Request $request) => UserResource::make(
            $request->user()->load(['extensions.dialableNumber', 'extensions.provisioningState']),
        ));
        Route::delete('auth/logout', [AuthenticatedSessionController::class, 'destroy']);
        Route::post('email/verification-notification', EmailVerificationNotificationController::class)
            ->middleware('throttle:3,1')
            ->name('verification.send');

        Route::middleware('verified')->group(function (): void {
            Route::get('webrtc/bootstrap', WebRtcBootstrapController::class)
                ->middleware('throttle:webrtc-bootstrap')
                ->name('webrtc.bootstrap');

            Route::apiResource('organizations', OrganizationController::class)->except('destroy');

            Route::scopeBindings()->group(function (): void {
                Route::apiResource('organizations.extensions', ExtensionController::class);
                Route::apiResource('organizations.conference-rooms', ConferenceRoomController::class)
                    ->only(['index', 'store', 'show'])
                    ->parameters(['conference-rooms' => 'conferenceRoom']);
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/join',
                    [ConferenceRoomController::class, 'join'],
                )->name('organizations.conference-rooms.join');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/invite',
                    [ConferenceRoomController::class, 'invite'],
                )->name('organizations.conference-rooms.invite');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/leave',
                    [ConferenceRoomController::class, 'leave'],
                )->name('organizations.conference-rooms.leave');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/end',
                    [ConferenceRoomController::class, 'end'],
                )->name('organizations.conference-rooms.end');
                Route::post(
                    'organizations/{organization}/extensions/{extension}/credentials/rotate',
                    SipCredentialController::class,
                )->name('organizations.extensions.credentials.rotate');
                Route::get(
                    'organizations/{organization}/extensions/{extension}/sip-registration',
                    SipRegistrationController::class,
                )->middleware('throttle:sip-registration')
                    ->name('organizations.extensions.sip-registration.show');
                Route::apiResource('organizations.service-numbers', ServiceNumberController::class)
                    ->parameters(['service-numbers' => 'serviceNumber']);
            });
        });
    });
});

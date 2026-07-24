<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
use App\Http\Controllers\Api\V1\CallLogController;
use App\Http\Controllers\Api\V1\CallRecordingController;
use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\ConferenceRecordingController;
use App\Http\Controllers\Api\V1\ConferenceRoomChatController;
use App\Http\Controllers\Api\V1\ConferenceRoomController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\ExtensionController;
use App\Http\Controllers\Api\V1\FriendshipController;
use App\Http\Controllers\Api\V1\MessageController;
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
            Route::get('conference-rooms/resolve', [ConferenceRoomController::class, 'resolve'])
                ->name('conference-rooms.resolve');
            Route::post('conference-rooms/join-by-invite', [ConferenceRoomController::class, 'joinByInvite'])
                ->name('conference-rooms.join-by-invite');
            Route::post('conference-rooms/leave-by-invite', [ConferenceRoomController::class, 'leaveByInvite'])
                ->name('conference-rooms.leave-by-invite');
            Route::get('conference-rooms/{conferenceRoom}/chat', [ConferenceRoomChatController::class, 'show'])
                ->name('conference-rooms.chat.show');
            Route::get('conference-rooms/{conferenceRoom}/chat/stream', [ConferenceRoomChatController::class, 'stream'])
                ->name('conference-rooms.chat.stream');
            Route::post('conference-rooms/{conferenceRoom}/chat/messages', [ConferenceRoomChatController::class, 'store'])
                ->name('conference-rooms.chat.messages.store');

            Route::apiResource('organizations', OrganizationController::class)->except('destroy');
            Route::get('people/search', [FriendshipController::class, 'search']);
            Route::apiResource('friendships', FriendshipController::class)->only(['index', 'store', 'show']);
            Route::post('friendships/{friendship}/respond', [FriendshipController::class, 'update'])
                ->name('friendships.respond');
            Route::apiResource('communities', CommunityController::class)->only(['index', 'store', 'show']);
            Route::post('communities/{community}/join', [CommunityController::class, 'join'])
                ->name('communities.join');
            Route::post('communities/{community}/invite', [CommunityController::class, 'invite'])
                ->name('communities.invite');
            Route::post('communities/{community}/departments', [CommunityController::class, 'storeDepartment'])
                ->name('communities.departments.store');
            Route::post('communities/{community}/members/{user}/department', [CommunityController::class, 'assignDepartment'])
                ->name('communities.members.department');
            Route::apiResource('conversations', ConversationController::class)->only(['index', 'store', 'show']);
            Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])
                ->name('conversations.messages.store');

            Route::scopeBindings()->group(function (): void {
                Route::apiResource('organizations.extensions', ExtensionController::class);
                Route::apiResource('organizations.departments', DepartmentController::class)
                    ->only(['index', 'store', 'update']);
                Route::get('organizations/{organization}/members', [OrganizationController::class, 'members'])
                    ->name('organizations.members.index');
                Route::post('organizations/{organization}/members', [OrganizationController::class, 'inviteMember'])
                    ->name('organizations.members.invite');
                Route::apiResource('organizations.call-logs', CallLogController::class)
                    ->parameters(['call-logs' => 'callLog']);
                Route::post(
                    'organizations/{organization}/call-logs/{callLog}/recording/start',
                    [CallRecordingController::class, 'start'],
                )->name('organizations.call-logs.recording.start');
                Route::post(
                    'organizations/{organization}/call-logs/{callLog}/recording/stop',
                    [CallRecordingController::class, 'stop'],
                )->name('organizations.call-logs.recording.stop');
                Route::post(
                    'organizations/{organization}/call-logs/{callLog}/recording/chunks',
                    [CallRecordingController::class, 'uploadChunk'],
                )->name('organizations.call-logs.recording.upload-chunk');
                Route::post(
                    'organizations/{organization}/call-logs/{callLog}/recording/finalize',
                    [CallRecordingController::class, 'finalizeUpload'],
                )->name('organizations.call-logs.recording.finalize-upload');
                Route::get(
                    'organizations/{organization}/call-logs/{callLog}/recording',
                    [CallRecordingController::class, 'show'],
                )->name('organizations.call-logs.recording.show');
                Route::delete(
                    'organizations/{organization}/call-logs/{callLog}/recording',
                    [CallRecordingController::class, 'destroy'],
                )->name('organizations.call-logs.recording.destroy');
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
                Route::get(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/waiting-participants',
                    [ConferenceRoomController::class, 'waitingParticipants'],
                )->name('organizations.conference-rooms.waiting-participants');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/participants/{participant}/admit',
                    [ConferenceRoomController::class, 'admitParticipant'],
                )->name('organizations.conference-rooms.participants.admit');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/participants/{participant}/deny',
                    [ConferenceRoomController::class, 'denyParticipant'],
                )->name('organizations.conference-rooms.participants.deny');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/participants/{participant}/remove',
                    [ConferenceRoomController::class, 'removeParticipant'],
                )->name('organizations.conference-rooms.participants.remove');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/participants/{participant}/mute',
                    [ConferenceRoomController::class, 'muteParticipant'],
                )->name('organizations.conference-rooms.participants.mute');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/participants/{participant}/unmute',
                    [ConferenceRoomController::class, 'unmuteParticipant'],
                )->name('organizations.conference-rooms.participants.unmute');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/participants/{participant}/video-off',
                    [ConferenceRoomController::class, 'videoOffParticipant'],
                )->name('organizations.conference-rooms.participants.video-off');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/participants/{participant}/video-on',
                    [ConferenceRoomController::class, 'videoOnParticipant'],
                )->name('organizations.conference-rooms.participants.video-on');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/participants/{participant}/camera-state',
                    [ConferenceRoomController::class, 'updateCameraState'],
                )->name('organizations.conference-rooms.participants.camera-state');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/participants/{participant}/screen-share-start',
                    [ConferenceRoomController::class, 'startScreenShare'],
                )->name('organizations.conference-rooms.participants.screen-share-start');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/participants/{participant}/screen-share-stop',
                    [ConferenceRoomController::class, 'stopScreenShare'],
                )->name('organizations.conference-rooms.participants.screen-share-stop');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/participants/{participant}/screen-share-off',
                    [ConferenceRoomController::class, 'screenShareOffParticipant'],
                )->name('organizations.conference-rooms.participants.screen-share-off');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/participants/{participant}/screen-share-on',
                    [ConferenceRoomController::class, 'screenShareOnParticipant'],
                )->name('organizations.conference-rooms.participants.screen-share-on');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/reactions',
                    [ConferenceRoomController::class, 'react'],
                )->name('organizations.conference-rooms.reactions.store');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/leave',
                    [ConferenceRoomController::class, 'leave'],
                )->name('organizations.conference-rooms.leave');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/presence/heartbeat',
                    [ConferenceRoomController::class, 'heartbeat'],
                )->name('organizations.conference-rooms.presence.heartbeat');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/presence/disconnect',
                    [ConferenceRoomController::class, 'disconnect'],
                )->name('organizations.conference-rooms.presence.disconnect');
                Route::post(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/end',
                    [ConferenceRoomController::class, 'end'],
                )->name('organizations.conference-rooms.end');
                Route::delete(
                    'organizations/{organization}/conference-rooms/{conferenceRoom}/recordings/{conferenceRecording}',
                    [ConferenceRecordingController::class, 'destroy'],
                )->name('organizations.conference-rooms.recordings.destroy');
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

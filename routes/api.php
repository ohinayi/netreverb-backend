<?php

use App\Http\Controllers\Api\V1\AiAssistantController;
use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
use App\Http\Controllers\Api\V1\CallLogController;
use App\Http\Controllers\Api\V1\CallQueueController;
use App\Http\Controllers\Api\V1\CallRecordingController;
use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\ConferenceRecordingController;
use App\Http\Controllers\Api\V1\ConferenceRoomChatController;
use App\Http\Controllers\Api\V1\ConferenceRoomController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\ExtensionController;
use App\Http\Controllers\Api\V1\FriendshipController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeadFollowUpController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OutboundCampaignController;
use App\Http\Controllers\Api\V1\OutboundDeliveryWebhookController;
use App\Http\Controllers\Api\V1\OutboundMessagingController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\ServiceNumberController;
use App\Http\Controllers\Api\V1\SipCredentialController;
use App\Http\Controllers\Api\V1\SipRegistrationController;
use App\Http\Controllers\Api\V1\SmsWalletController;
use App\Http\Controllers\Api\V1\SuperAdminAnalyticsController;
use App\Http\Controllers\Api\V1\SuperAdminSmsController;
use App\Http\Controllers\Api\V1\WebRtcBootstrapController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\FreeSwitchCallcenterConfigurationController;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Session\Middleware\StartSession;


Route::match(['get', 'post'], 'freeswitch/callcenter.xml', FreeSwitchCallcenterConfigurationController::class)
    ->name('freeswitch.callcenter.configuration');

Route::prefix('v1')->group(function (): void {
    Route::post('payments/webhooks/{provider}', PaymentWebhookController::class)
        ->middleware('throttle:240,1')
        ->name('payments.webhooks');
    Route::post('outbound/webhooks/{provider}', OutboundDeliveryWebhookController::class)
        ->middleware('throttle:120,1')
        ->name('outbound.webhooks.delivery');
    Route::post('auth/register', RegisteredUserController::class)
        ->middleware([StartSession::class, 'throttle:auth-registration']);
    Route::post('auth/resend-verification', EmailVerificationNotificationController::class)
        ->middleware('throttle:password-recovery');
    Route::post('auth/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware([StartSession::class, 'throttle:auth-login']);
    Route::post('auth/forgot-password', [PasswordResetController::class, 'store'])
        ->middleware('throttle:password-recovery');
    Route::post('auth/reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:password-reset');
    Route::get('email/verify/{id}/{hash}', EmailVerificationController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::get('email/verify-required', fn() => response()->json([
        'message' => 'Email verification is required.',
    ], 403))->name('verification.notice');

    Route::middleware([StartSession::class, 'auth:sanctum', 'throttle:120,1'])->group(function (): void {
        Route::get('/me', fn(Request $request) => UserResource::make(
            $request->user()->load([
                'extensions.dialableNumber',
                'extensions.organization',
                'extensions.provisioningState',
            ]),
        ));
        Route::delete('auth/logout', [AuthenticatedSessionController::class, 'destroy'])
            ->middleware(StartSession::class);
        Route::post('email/verification-notification', EmailVerificationNotificationController::class)
            ->middleware('throttle:3,1')
            ->name('verification.send');

        Route::middleware('verified')->group(function (): void {
            Route::get('notifications', [NotificationController::class, 'index'])
                ->name('notifications.index');
            Route::patch('notifications/read-all', [NotificationController::class, 'readAll'])
                ->name('notifications.read-all');
            Route::patch('notifications/{notification}/read', [NotificationController::class, 'read'])
                ->name('notifications.read');
            Route::get('super-admin/analytics', SuperAdminAnalyticsController::class)
                ->name('super-admin.analytics');
            Route::get('super-admin/sms', [SuperAdminSmsController::class, 'index'])
                ->name('super-admin.sms.index');
            Route::patch('super-admin/sms/pricing', [SuperAdminSmsController::class, 'updatePricing'])
                ->name('super-admin.sms.pricing.update');
            Route::post(
                'super-admin/sms/purchases/{smsCreditPurchase}/complete',
                [SuperAdminSmsController::class, 'completePurchase'],
            )->name('super-admin.sms.purchases.complete');
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
            Route::apiResource('organizations.workspaces', WorkspaceController::class)
                ->except(['create', 'edit'])
                ->parameters(['workspaces' => 'workspace']);
            Route::get('leads', [LeadController::class, 'all'])->name('leads.index');
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
                ->middleware('throttle:message-send')
                ->name('conversations.messages.store');

            Route::scopeBindings()->group(function (): void {
                Route::apiResource('organizations.extensions', ExtensionController::class);
                Route::get('organizations/{organization}/audit-events', [AuditEventController::class, 'index'])
                    ->name('organizations.audit-events.index');
                Route::apiResource('organizations.call-queues', CallQueueController::class)
                    ->only(['index', 'store', 'update', 'destroy'])
                    ->parameters(['call-queues' => 'callQueue']);
                Route::apiResource('organizations.leads', LeadController::class)
                    ->only(['index', 'store', 'update', 'destroy']);
                Route::get('organizations/{organization}/leads/{lead}/activities', [LeadController::class, 'activities'])
                    ->name('organizations.leads.activities.index');
                Route::post('organizations/{organization}/leads/{lead}/activities', [LeadController::class, 'storeActivity'])
                    ->name('organizations.leads.activities.store');
                Route::post('organizations/{organization}/leads/{lead}/follow-up/complete', [LeadFollowUpController::class, 'complete'])
                    ->name('organizations.leads.follow-up.complete');
                Route::post('organizations/{organization}/leads/{lead}/follow-up/snooze', [LeadFollowUpController::class, 'snooze'])
                    ->name('organizations.leads.follow-up.snooze');
                Route::get('organizations/{organization}/outbound-messaging', [OutboundMessagingController::class, 'index'])
                    ->name('organizations.outbound-messaging.index');
                Route::get('organizations/{organization}/sms-wallet', [SmsWalletController::class, 'show'])
                    ->name('organizations.sms-wallet.show');
                Route::post('organizations/{organization}/sms-wallet/purchases', [SmsWalletController::class, 'requestPurchase'])
                    ->middleware('throttle:10,1')
                    ->name('organizations.sms-wallet.purchases.store');
                Route::post(
                    'organizations/{organization}/sms-wallet/purchases/{smsCreditPurchase}/verify',
                    [SmsWalletController::class, 'verifyPurchase'],
                    // Verification is idempotent. Keep abuse protection, but allow
                    // a user to retry after provider settlement without immediately
                    // hitting the normal purchase-creation limiter.
                )->middleware('throttle:30,1')->name('organizations.sms-wallet.purchases.verify');
                Route::post('organizations/{organization}/message-templates', [OutboundMessagingController::class, 'storeTemplate'])
                    ->name('organizations.message-templates.store');
                Route::post('organizations/{organization}/message-templates/{messageTemplate}/review', [OutboundMessagingController::class, 'reviewTemplate'])
                    ->name('organizations.message-templates.review');
                Route::put('organizations/{organization}/leads/{lead}/contact-channel', [OutboundMessagingController::class, 'updateContactChannel'])
                    ->name('organizations.leads.contact-channel.update');
                Route::post('organizations/{organization}/outbound-messages', [OutboundMessagingController::class, 'createDraft'])
                    ->name('organizations.outbound-messages.store');
                Route::post('organizations/{organization}/outbound-messages/{outboundMessage}/approve', [OutboundMessagingController::class, 'approve'])
                    ->name('organizations.outbound-messages.approve');
                Route::get('organizations/{organization}/outbound-campaigns', [OutboundCampaignController::class, 'index'])
                    ->name('organizations.outbound-campaigns.index');
                Route::post('organizations/{organization}/outbound-campaigns', [OutboundCampaignController::class, 'store'])
                    ->name('organizations.outbound-campaigns.store');
                Route::post('organizations/{organization}/outbound-campaigns/{outboundCampaign}/start', [OutboundCampaignController::class, 'start'])
                    ->name('organizations.outbound-campaigns.start');
                Route::post('organizations/{organization}/outbound-campaigns/{outboundCampaign}/cancel', [OutboundCampaignController::class, 'cancel'])
                    ->name('organizations.outbound-campaigns.cancel');
                Route::get('organizations/{organization}/leads-export', [LeadController::class, 'export'])
                    ->name('organizations.leads.export');
                Route::post('organizations/{organization}/leads-import', [LeadController::class, 'import'])
                    ->middleware('throttle:10,1')
                    ->name('organizations.leads.import');
                Route::apiResource('organizations.ai-assistants', AiAssistantController::class)
                    ->only(['index', 'store', 'update', 'destroy'])
                    ->parameters(['ai-assistants' => 'aiAssistant']);
                Route::get('organizations/{organization}/ai-assistants/{aiAssistant}/sessions', [AiAssistantController::class, 'sessions'])
                    ->name('organizations.ai-assistants.sessions.index');
                Route::apiResource('organizations.departments', DepartmentController::class)
                    ->only(['index', 'store', 'update']);
                Route::get('organizations/{organization}/members', [OrganizationController::class, 'members'])
                    ->name('organizations.members.index');
                Route::post('organizations/{organization}/members', [OrganizationController::class, 'inviteMember'])
                    ->name('organizations.members.invite');
                Route::patch('organizations/{organization}/members/{membership}', [OrganizationController::class, 'updateMember'])
                    ->name('organizations.members.update');
                Route::apiResource('organizations.call-logs', CallLogController::class)
                    ->parameters(['call-logs' => 'callLog']);
                Route::post('organizations/{organization}/call-logs/{callLog}/transfer', [CallLogController::class, 'transfer'])
                    ->name('organizations.call-logs.transfer');
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

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SnoozeLeadFollowUpRequest;
use App\Models\Lead;
use App\Models\Organization;
use App\Services\Auditing\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LeadFollowUpController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function complete(Request $request, Organization $organization, Lead $lead): JsonResponse
    {
        $this->authorizeLeadAction($request, $organization, $lead);

        $lead->forceFill([
            'follow_up_at' => null,
            'follow_up_completed_at' => now(),
            'follow_up_notified_at' => null,
        ])->save();

        $this->readReminderNotifications($request, $lead);

        $this->auditLogger->record(
            $request,
            $request->user(),
            $organization,
            'lead.follow_up.completed',
            $lead,
            null,
            ['lead_public_id' => $lead->public_id],
        );

        return response()->json(['message' => 'Follow-up completed.']);
    }

    public function snooze(
        SnoozeLeadFollowUpRequest $request,
        Organization $organization,
        Lead $lead,
    ): JsonResponse {
        $this->authorizeLeadAction($request, $organization, $lead);

        $lead->forceFill([
            'follow_up_at' => $request->date('until'),
            'follow_up_completed_at' => null,
            'follow_up_notified_at' => null,
        ])->save();

        $this->readReminderNotifications($request, $lead);

        $this->auditLogger->record(
            $request,
            $request->user(),
            $organization,
            'lead.follow_up.snoozed',
            $lead,
            null,
            [
                'lead_public_id' => $lead->public_id,
                'until' => $lead->follow_up_at?->toIso8601String(),
            ],
        );

        return response()->json([
            'message' => 'Follow-up rescheduled.',
            'follow_up_at' => $lead->follow_up_at?->toIso8601String(),
        ]);
    }

    private function authorizeLeadAction(Request $request, Organization $organization, Lead $lead): void
    {
        abort_unless($lead->organization_id === $organization->id, 404);

        if ($lead->assigned_user_id !== $request->user()->id) {
            Gate::authorize('update', $organization);
        }
    }

    private function readReminderNotifications(Request $request, Lead $lead): void
    {
        $request->user()->unreadNotifications
            ->filter(fn ($notification) => ($notification->data['kind'] ?? null) === 'lead_follow_up_due'
                && ($notification->data['lead']['id'] ?? null) === $lead->public_id)
            ->each->markAsRead();
    }
}

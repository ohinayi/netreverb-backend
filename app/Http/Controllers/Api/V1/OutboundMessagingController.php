<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadContactChannel;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\OutboundMessage;
use App\Services\Auditing\AuditLogger;
use App\Services\Messaging\OutboundMessagingReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OutboundMessagingController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly OutboundMessagingReadiness $readiness,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageOutboundMessaging', $organization);

        return response()->json([
            'templates' => $organization->messageTemplates()
                ->latest()
                ->get()
                ->map(fn (MessageTemplate $template) => $this->templateData($template)),
            'messages' => $organization->outboundMessages()
                ->with('lead:id,public_id,name,company')
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (OutboundMessage $message) => $this->messageData($message)),
            'provider_status' => $this->readiness->status(),
        ]);
    }

    public function storeTemplate(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageOutboundMessaging', $organization);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'channel' => ['required', Rule::in(['sms', 'email'])],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $template = $organization->messageTemplates()->create([
            ...$data,
            'created_by_user_id' => $request->user()->id,
            'status' => 'draft',
        ]);
        $this->auditLogger->record($request, $request->user(), $organization, 'message_template.created', $template);

        return response()->json(['data' => $this->templateData($template)], 201);
    }

    public function reviewTemplate(
        Request $request,
        Organization $organization,
        MessageTemplate $messageTemplate,
    ): JsonResponse {
        Gate::authorize('manageOutboundMessaging', $organization);
        abort_unless($messageTemplate->organization_id === $organization->id, 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $messageTemplate->update([
            'status' => $data['decision'],
            'review_note' => $data['note'] ?? null,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        $this->auditLogger->record($request, $request->user(), $organization, 'message_template.reviewed', $messageTemplate, null, $data);

        return response()->json(['data' => $this->templateData($messageTemplate)]);
    }

    public function updateContactChannel(
        Request $request,
        Organization $organization,
        Lead $lead,
    ): JsonResponse {
        Gate::authorize('manageOutboundMessaging', $organization);
        abort_unless($lead->organization_id === $organization->id, 404);
        $data = $request->validate([
            'channel' => ['required', Rule::in(['sms', 'email'])],
            'destination' => ['required', 'string', 'max:255'],
            'consent_status' => ['required', Rule::in(['unknown', 'granted', 'revoked'])],
            'consent_source' => ['nullable', 'string', 'max:255'],
            'suppressed' => ['required', 'boolean'],
            'suppression_reason' => ['nullable', 'required_if:suppressed,true', 'string', 'max:255'],
        ]);

        $contact = LeadContactChannel::query()->updateOrCreate(
            ['lead_id' => $lead->id, 'channel' => $data['channel']],
            [
                'organization_id' => $organization->id,
                'recorded_by_user_id' => $request->user()->id,
                'destination' => $this->normalizeDestination($data['channel'], $data['destination']),
                'consent_status' => $data['consent_status'],
                'consent_source' => $data['consent_source'] ?? null,
                'consented_at' => $data['consent_status'] === 'granted' ? now() : null,
                'suppressed_at' => $data['suppressed'] ? now() : null,
                'suppression_reason' => $data['suppressed'] ? $data['suppression_reason'] : null,
            ],
        );
        $this->auditLogger->record($request, $request->user(), $organization, 'lead.contact_preference.updated', $lead, null, $data);

        return response()->json(['data' => $this->contactData($contact)]);
    }

    public function createDraft(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageOutboundMessaging', $organization);
        $data = $request->validate([
            'lead_public_id' => ['required', 'string'],
            'template_public_id' => ['required', 'string'],
        ]);
        $lead = $organization->leads()->where('public_id', $data['lead_public_id'])->firstOrFail();
        $template = $organization->messageTemplates()->where('public_id', $data['template_public_id'])->firstOrFail();
        $contact = LeadContactChannel::query()
            ->whereBelongsTo($organization)
            ->whereBelongsTo($lead)
            ->where('channel', $template->channel)
            ->first();
        $blockedReason = $this->blockedReason($template, $contact);

        $message = $organization->outboundMessages()->create([
            'lead_id' => $lead->id,
            'message_template_id' => $template->id,
            'created_by_user_id' => $request->user()->id,
            'channel' => $template->channel,
            'destination' => $contact?->destination ?? '',
            'body' => $this->render($template->body, $lead, $organization),
            'status' => $blockedReason ? 'blocked' : 'draft',
            'blocked_reason' => $blockedReason,
            'consent_snapshot' => $contact ? $this->contactData($contact) : null,
        ]);
        $message->load('lead:id,public_id,name,company');
        $this->auditLogger->record($request, $request->user(), $organization, 'outbound_message.drafted', $message);

        return response()->json(['data' => $this->messageData($message)], 201);
    }

    public function approve(
        Request $request,
        Organization $organization,
        OutboundMessage $outboundMessage,
    ): JsonResponse {
        Gate::authorize('manageOutboundMessaging', $organization);
        abort_unless($outboundMessage->organization_id === $organization->id, 404);
        $contact = LeadContactChannel::query()
            ->where('lead_id', $outboundMessage->lead_id)
            ->where('channel', $outboundMessage->channel)
            ->first();
        $template = $outboundMessage->template;
        $blockedReason = $template ? $this->blockedReason($template, $contact) : 'The template no longer exists.';
        abort_if($blockedReason, 422, $blockedReason);

        $outboundMessage->update([
            'status' => 'approved',
            'blocked_reason' => null,
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
            'consent_snapshot' => $this->contactData($contact),
        ]);
        $outboundMessage->load('lead:id,public_id,name,company');
        $this->auditLogger->record($request, $request->user(), $organization, 'outbound_message.approved', $outboundMessage);

        return response()->json([
            'data' => $this->messageData($outboundMessage),
            'message' => 'Approved and held. Live provider sending is disabled.',
        ]);
    }

    private function blockedReason(MessageTemplate $template, ?LeadContactChannel $contact): ?string
    {
        if ($template->status !== 'approved') {
            return 'The template has not been approved.';
        }
        if (! $contact || $contact->consent_status !== 'granted') {
            return 'Verified consent has not been recorded for this channel.';
        }
        if ($contact->suppressed_at) {
            return 'This destination is suppressed and must not be contacted.';
        }

        return null;
    }

    private function normalizeDestination(string $channel, string $destination): string
    {
        return $channel === 'email'
            ? mb_strtolower(trim($destination))
            : preg_replace('/[^\d+]/', '', $destination) ?? '';
    }

    private function render(string $body, Lead $lead, Organization $organization): string
    {
        return strtr($body, [
            '{{lead.name}}' => $lead->name,
            '{{lead.first_name}}' => explode(' ', trim($lead->name))[0] ?? $lead->name,
            '{{lead.company}}' => $lead->company ?? '',
            '{{organization.name}}' => $organization->name,
        ]);
    }

    private function templateData(MessageTemplate $template): array
    {
        return $template->only(['public_id', 'name', 'channel', 'body', 'status', 'review_note', 'reviewed_at', 'created_at']);
    }

    private function contactData(LeadContactChannel $contact): array
    {
        return $contact->only([
            'public_id', 'channel', 'destination', 'consent_status', 'consent_source',
            'consented_at', 'suppressed_at', 'suppression_reason',
        ]);
    }

    private function messageData(OutboundMessage $message): array
    {
        return [
            ...$message->only([
                'public_id', 'channel', 'destination', 'body', 'status', 'blocked_reason',
                'sms_units', 'billing_status', 'approved_at', 'sent_at', 'delivered_at',
                'failed_at', 'failure_reason', 'created_at',
            ]),
            'lead' => $message->relationLoaded('lead') && $message->lead ? [
                'id' => $message->lead->public_id,
                'name' => $message->lead->name,
                'company' => $message->lead->company,
            ] : null,
        ];
    }
}

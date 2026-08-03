<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OutboundCampaign;
use App\Services\Auditing\AuditLogger;
use App\Services\Messaging\OutboundMessagingReadiness;
use App\Services\Messaging\SmsCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OutboundCampaignController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly OutboundMessagingReadiness $readiness,
        private readonly SmsCreditService $credits,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageOutboundMessaging', $organization);
        $campaigns = OutboundCampaign::query()
            ->whereBelongsTo($organization)
            ->with(['template:id,public_id,name,channel,status'])
            ->withCount([
                'recipients',
                'recipients as pending_count' => fn ($query) => $query->whereIn('status', ['pending', 'queued']),
                'recipients as delivered_count' => fn ($query) => $query->where('status', 'delivered'),
                'recipients as blocked_count' => fn ($query) => $query->where('status', 'blocked'),
                'recipients as failed_count' => fn ($query) => $query->where('status', 'failed'),
            ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => collect($campaigns->items())->map(fn (OutboundCampaign $campaign) => $this->data($campaign)),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
            'provider_status' => $this->readiness->status(),
        ]);
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageOutboundMessaging', $organization);
        abort_unless($organization->policyAllows('campaigns_enabled'), 403, 'Campaigns are disabled by organization policy.');
        $policy = $organization->operationalPolicy();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'template_public_id' => ['required', 'string'],
            'lead_public_ids' => ['required', 'array', 'min:1', 'max:'.$policy['campaign_max_recipients']],
            'lead_public_ids.*' => ['string', 'distinct'],
            'timezone' => ['required', 'timezone'],
            'quiet_hours_start' => ['required', 'date_format:H:i'],
            'quiet_hours_end' => ['required', 'date_format:H:i'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:'.$policy['campaign_rate_limit_per_minute']],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);
        $template = $organization->messageTemplates()
            ->where('public_id', $data['template_public_id'])
            ->where('status', 'approved')
            ->firstOrFail();
        $leads = $organization->leads()->whereIn('public_id', $data['lead_public_ids'])->get(['id']);
        abort_if($leads->count() !== count($data['lead_public_ids']), 422, 'One or more leads are outside this organization.');

        $campaign = OutboundCampaign::query()->create([
            'organization_id' => $organization->id,
            'message_template_id' => $template->id,
            'created_by_user_id' => $request->user()->id,
            'name' => $data['name'],
            'channel' => $template->channel,
            'status' => 'draft',
            'timezone' => $data['timezone'],
            'quiet_hours_start' => $data['quiet_hours_start'],
            'quiet_hours_end' => $data['quiet_hours_end'],
            'rate_limit_per_minute' => $data['rate_limit_per_minute'],
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ]);
        $campaign->recipients()->createMany($leads->map(fn ($lead) => ['lead_id' => $lead->id])->all());
        $campaign->load('template:id,public_id,name,channel,status')->loadCount('recipients');
        $this->auditLogger->record($request, $request->user(), $organization, 'outbound_campaign.created', $campaign, null, [
            'recipient_count' => $leads->count(),
        ]);

        return response()->json(['data' => $this->data($campaign)], 201);
    }

    public function start(Request $request, Organization $organization, OutboundCampaign $outboundCampaign): JsonResponse
    {
        Gate::authorize('manageOutboundMessaging', $organization);
        abort_unless($organization->policyAllows('campaigns_enabled'), 403, 'Campaigns are disabled by organization policy.');
        abort_unless($outboundCampaign->organization_id === $organization->id, 404);
        abort_unless($outboundCampaign->status === 'draft', 422, 'Only a draft campaign can be started.');
        abort_unless(
            $this->readiness->canSend(),
            422,
            'Live outbound messaging is disabled or the selected provider is incomplete.',
        );
        abort_if(
            $outboundCampaign->channel === 'sms'
                && $this->credits->wallet($organization)->balance_units < 1,
            422,
            'Purchase SMS credit before starting this campaign.',
        );

        $outboundCampaign->update([
            'status' => $outboundCampaign->scheduled_at?->isFuture() ? 'scheduled' : 'running',
            'started_at' => $outboundCampaign->scheduled_at?->isFuture() ? null : now(),
        ]);
        $this->auditLogger->record($request, $request->user(), $organization, 'outbound_campaign.started', $outboundCampaign);

        return response()->json(['message' => 'Campaign accepted for guarded dispatch.']);
    }

    public function cancel(Request $request, Organization $organization, OutboundCampaign $outboundCampaign): JsonResponse
    {
        Gate::authorize('manageOutboundMessaging', $organization);
        abort_unless($outboundCampaign->organization_id === $organization->id, 404);
        abort_if(in_array($outboundCampaign->status, ['completed', 'cancelled'], true), 422, 'Campaign is already closed.');
        $outboundCampaign->update(['status' => 'cancelled']);
        $this->auditLogger->record($request, $request->user(), $organization, 'outbound_campaign.cancelled', $outboundCampaign);

        return response()->json(['message' => 'Campaign cancelled.']);
    }

    private function data(OutboundCampaign $campaign): array
    {
        return [
            ...$campaign->only([
                'public_id', 'name', 'channel', 'status', 'timezone', 'quiet_hours_start',
                'quiet_hours_end', 'rate_limit_per_minute', 'scheduled_at', 'started_at',
                'completed_at', 'created_at',
            ]),
            'template' => $campaign->relationLoaded('template') ? $campaign->template?->only(['public_id', 'name', 'channel', 'status']) : null,
            'counts' => [
                'total' => $campaign->recipients_count ?? 0,
                'pending' => $campaign->pending_count ?? 0,
                'delivered' => $campaign->delivered_count ?? 0,
                'blocked' => $campaign->blocked_count ?? 0,
                'failed' => $campaign->failed_count ?? 0,
            ],
        ];
    }
}

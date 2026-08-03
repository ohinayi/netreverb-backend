<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OutboundCampaignRecipient;
use App\Models\OutboundMessage;
use App\Services\Auditing\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OutboundDeliveryWebhookController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $secret = (string) config('outbound.webhook_secret');
        abort_if($secret === '', 503, 'Outbound webhook secret is not configured.');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless(hash_equals($expected, (string) $request->header('X-NetReverb-Signature')), 401);
        $data = $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['sent', 'delivered', 'failed'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $message = OutboundMessage::query()
            ->where('provider', $provider)
            ->where('provider_message_id', $data['message_id'])
            ->firstOrFail();
        $timestampField = match ($data['status']) {
            'sent' => 'sent_at',
            'delivered' => 'delivered_at',
            'failed' => 'failed_at',
        };
        $message->update([
            'status' => $data['status'],
            $timestampField => now(),
            'failure_reason' => $data['status'] === 'failed' ? ($data['reason'] ?? 'Provider rejected the message.') : null,
        ]);
        OutboundCampaignRecipient::query()
            ->where('outbound_message_id', $message->id)
            ->update([
                'status' => $data['status'],
                'blocked_reason' => $data['status'] === 'failed' ? ($data['reason'] ?? null) : null,
                'processed_at' => now(),
            ]);
        $message->load('organization');
        $this->auditLogger->record($request, null, $message->organization, 'outbound_message.delivery_updated', $message, null, [
            'provider' => $provider,
            'status' => $data['status'],
        ]);

        return response()->json(['received' => true]);
    }
}

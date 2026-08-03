<?php

namespace App\Jobs;

use App\Contracts\Messaging\OutboundMessageProvider;
use App\Exceptions\Messaging\IndeterminateOutboundMessageException;
use App\Exceptions\Messaging\InsufficientSmsCreditException;
use App\Exceptions\Messaging\PermanentOutboundMessageException;
use App\Models\LeadContactChannel;
use App\Models\OutboundCampaignRecipient;
use App\Models\OutboundMessage;
use App\Services\Messaging\SmsCreditService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

class DispatchOutboundCampaignRecipient implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public int $recipientId)
    {
        $this->onQueue('outbound');
    }

    public function uniqueId(): string
    {
        return (string) $this->recipientId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 60, 300, 900];
    }

    public function handle(OutboundMessageProvider $provider, SmsCreditService $credits): void
    {
        if (! config('outbound.sending_enabled')) {
            throw new RuntimeException('Live outbound messaging is disabled.');
        }

        $recipient = OutboundCampaignRecipient::query()
            ->with(['campaign.template.organization', 'lead'])
            ->findOrFail($this->recipientId);

        if (! in_array($recipient->status, ['pending', 'queued'], true)) {
            return;
        }

        $campaign = $recipient->campaign;
        if (! in_array($campaign->status, ['running', 'scheduled'], true)) {
            return;
        }

        if ($this->insideQuietHours($campaign)) {
            $this->release(300);

            return;
        }

        $rateKey = "outbound-campaign:{$campaign->organization_id}";
        if (RateLimiter::tooManyAttempts($rateKey, $campaign->rate_limit_per_minute)) {
            $this->release(max(1, RateLimiter::availableIn($rateKey)));

            return;
        }
        RateLimiter::hit($rateKey, 60);

        $contact = LeadContactChannel::query()
            ->where('lead_id', $recipient->lead_id)
            ->where('channel', $campaign->channel)
            ->first();
        $blocked = $this->blockedReason($campaign->template, $contact);
        if ($blocked) {
            $recipient->update(['status' => 'blocked', 'blocked_reason' => $blocked, 'processed_at' => now()]);

            return;
        }

        $message = DB::transaction(function () use ($recipient, $campaign, $contact): OutboundMessage {
            $locked = OutboundCampaignRecipient::query()->lockForUpdate()->findOrFail($recipient->id);
            if ($locked->outbound_message_id) {
                return OutboundMessage::query()->findOrFail($locked->outbound_message_id);
            }

            $message = OutboundMessage::query()->create([
                'idempotency_key' => "campaign:{$campaign->public_id}:lead:{$recipient->lead->public_id}",
                'organization_id' => $campaign->organization_id,
                'lead_id' => $recipient->lead_id,
                'message_template_id' => $campaign->message_template_id,
                'created_by_user_id' => $campaign->created_by_user_id,
                'approved_by_user_id' => $campaign->created_by_user_id,
                'channel' => $campaign->channel,
                'destination' => $contact->destination,
                'body' => $this->render($campaign->template->body, $recipient->lead, $campaign->template->organization),
                'status' => 'sending',
                'approved_at' => now(),
                'consent_snapshot' => $contact->toArray(),
            ]);
            $locked->update(['outbound_message_id' => $message->id, 'status' => 'queued']);

            return $message;
        });

        try {
            $credits->debitForMessage($message);
        } catch (InsufficientSmsCreditException $exception) {
            $message->update([
                'status' => 'blocked',
                'blocked_reason' => $exception->getMessage(),
                'billing_status' => 'insufficient_credit',
            ]);
            $recipient->update([
                'status' => 'blocked',
                'blocked_reason' => $exception->getMessage(),
                'processed_at' => now(),
            ]);

            return;
        }

        try {
            $result = $provider->send($message);
            $message->update([
                'status' => 'sent',
                'provider' => $result['provider'],
                'provider_message_id' => $result['message_id'],
                'sent_at' => now(),
            ]);
            $recipient->update(['status' => 'sent', 'attempts' => $recipient->attempts + 1, 'processed_at' => now()]);
        } catch (PermanentOutboundMessageException $exception) {
            $credits->refundMessage($message, 'Provider permanently rejected the SMS before acceptance.');
            $message->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => $exception->getMessage(),
            ]);
            $recipient->update([
                'status' => 'failed',
                'blocked_reason' => $exception->getMessage(),
                'attempts' => $recipient->attempts + 1,
                'processed_at' => now(),
            ]);
        } catch (IndeterminateOutboundMessageException $exception) {
            $message->update([
                'status' => 'unknown',
                'billing_status' => 'held_for_reconciliation',
                'failure_reason' => $exception->getMessage(),
            ]);
            $recipient->update([
                'status' => 'unknown',
                'blocked_reason' => $exception->getMessage(),
                'attempts' => $recipient->attempts + 1,
                'processed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $message->update(['status' => 'failed', 'failed_at' => now(), 'failure_reason' => $exception->getMessage()]);
            $recipient->increment('attempts');
            throw $exception;
        }
    }

    private function insideQuietHours($campaign): bool
    {
        $now = now($campaign->timezone);
        $start = $now->copy()->setTimeFromTimeString($campaign->quiet_hours_start);
        $end = $now->copy()->setTimeFromTimeString($campaign->quiet_hours_end);

        return $start->lte($end)
            ? $now->betweenIncluded($start, $end)
            : $now->gte($start) || $now->lte($end);
    }

    private function blockedReason($template, ?LeadContactChannel $contact): ?string
    {
        if (! $template || $template->status !== 'approved') {
            return 'Template is not approved.';
        }
        if (! $contact || $contact->consent_status !== 'granted') {
            return 'Consent is not granted.';
        }
        if ($contact->suppressed_at) {
            return 'Destination is suppressed.';
        }

        return null;
    }

    private function render(string $body, $lead, $organization): string
    {
        return strtr($body, [
            '{{lead.name}}' => $lead->name,
            '{{lead.first_name}}' => explode(' ', trim($lead->name))[0] ?? $lead->name,
            '{{lead.company}}' => $lead->company ?? '',
            '{{organization.name}}' => $organization->name,
        ]);
    }
}

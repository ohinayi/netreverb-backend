<?php

namespace App\Console\Commands;

use App\Jobs\DispatchOutboundCampaignRecipient;
use App\Models\OutboundCampaign;
use App\Services\Messaging\OutboundMessagingReadiness;
use Illuminate\Console\Command;

class DispatchDueOutboundCampaigns extends Command
{
    protected $signature = 'outbound:dispatch-due-campaigns';

    protected $description = 'Queue eligible recipients for due outbound campaigns';

    public function handle(OutboundMessagingReadiness $readiness): int
    {
        if (! $readiness->canSend()) {
            $this->line('Outbound sending is disabled or incomplete; no recipients were queued.');

            return self::SUCCESS;
        }

        $queued = 0;
        OutboundCampaign::query()
            ->whereIn('status', ['scheduled', 'running'])
            ->where(fn ($query) => $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->with(['recipients' => fn ($query) => $query->where('status', 'pending')])
            ->chunkById(25, function ($campaigns) use (&$queued): void {
                foreach ($campaigns as $campaign) {
                    $campaign->update(['status' => 'running', 'started_at' => $campaign->started_at ?? now()]);
                    foreach ($campaign->recipients as $recipient) {
                        $recipient->update(['status' => 'queued']);
                        DispatchOutboundCampaignRecipient::dispatch($recipient->id);
                        $queued++;
                    }
                }
            });

        $this->info("Queued {$queued} campaign recipient(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\ProvisioningEventStatus;
use App\Jobs\ProvisionSipSubscriber;
use App\Models\SipProvisioningEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sip:reconcile {--limit=100 : Maximum events to dispatch}')]
#[Description('Dispatch pending or failed SIP provisioning events')]
class ReconcileSipSubscribers extends Command
{
    public function handle(): int
    {
        $eventIds = SipProvisioningEvent::query()
            ->whereIn('status', [
                ProvisioningEventStatus::Pending,
                ProvisioningEventStatus::Failed,
            ])
            ->where(fn ($query) => $query
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now()))
            ->oldest('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        $eventIds->each(fn (int $eventId) => ProvisionSipSubscriber::dispatch($eventId));
        $this->info("Dispatched {$eventIds->count()} SIP provisioning event(s).");

        return self::SUCCESS;
    }
}

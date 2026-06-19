<?php

namespace App\Jobs;

use App\Contracts\Telephony\SipSubscriberGateway;
use App\Data\SipSubscriber;
use App\Enums\ExtensionStatus;
use App\Enums\ProvisioningEventStatus;
use App\Enums\ProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Models\Extension;
use App\Models\SipProvisioningEvent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProvisionSipSubscriber implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    public function __construct(public int $eventId)
    {
        $this->onQueue('telephony');
    }

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 300];
    }

    public function handle(SipSubscriberGateway $gateway): void
    {
        $event = $this->startEvent();

        if ($event === null) {
            return;
        }

        $extension = Extension::query()
            ->withTrashed()
            ->with(['dialableNumber', 'credential', 'provisioningState'])
            ->findOrFail($event->extension_id);
        $subscriber = new SipSubscriber(
            username: $extension->dialableNumber->number,
            realm: $extension->dialableNumber->realm,
            password: $extension->credential->password,
        );

        if ($event->operation === ProvisioningOperation::Upsert) {
            $gateway->upsert($subscriber);
        } else {
            $gateway->delete($subscriber->username, $subscriber->realm);
        }

        $this->completeEvent($event, $extension);
    }

    public function failed(?Throwable $exception): void
    {
        $event = SipProvisioningEvent::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        $event->update([
            'status' => ProvisioningEventStatus::Failed,
            'last_error' => 'SIP provisioning failed after all retry attempts.',
        ]);
        $event->extension()->withTrashed()->first()?->provisioningState()->update([
            'status' => ProvisioningStatus::Failed,
            'last_error' => 'SIP provisioning failed after all retry attempts.',
        ]);
    }

    private function startEvent(): ?SipProvisioningEvent
    {
        return DB::transaction(function (): ?SipProvisioningEvent {
            $event = SipProvisioningEvent::query()->lockForUpdate()->findOrFail($this->eventId);
            $state = $event->extension()->withTrashed()->firstOrFail()
                ->provisioningState()->lockForUpdate()->firstOrFail();

            if ($event->status === ProvisioningEventStatus::Completed) {
                return null;
            }

            if ($event->revision < $state->desired_revision) {
                $event->update([
                    'status' => ProvisioningEventStatus::Completed,
                    'processed_at' => now(),
                    'last_error' => null,
                ]);

                return null;
            }

            $event->update([
                'status' => ProvisioningEventStatus::Processing,
                'attempts' => $event->attempts + 1,
                'last_error' => null,
            ]);
            $state->update([
                'status' => ProvisioningStatus::Processing,
                'last_attempted_at' => now(),
                'last_error' => null,
            ]);

            return $event->refresh();
        });
    }

    private function completeEvent(SipProvisioningEvent $event, Extension $extension): void
    {
        DB::transaction(function () use ($event, $extension): void {
            $lockedEvent = SipProvisioningEvent::query()->lockForUpdate()->findOrFail($event->id);
            $state = $extension->provisioningState()->lockForUpdate()->firstOrFail();

            $lockedEvent->update([
                'status' => ProvisioningEventStatus::Completed,
                'processed_at' => now(),
                'last_error' => null,
            ]);

            if ($state->desired_revision !== $lockedEvent->revision) {
                return;
            }

            $isUpsert = $lockedEvent->operation === ProvisioningOperation::Upsert;
            $state->update([
                'applied_revision' => $lockedEvent->revision,
                'status' => $isUpsert ? ProvisioningStatus::Active : ProvisioningStatus::Disabled,
                'provisioned_at' => $isUpsert ? now() : null,
                'last_error' => null,
            ]);

            if ($isUpsert && $extension->status === ExtensionStatus::Pending) {
                $extension->update(['status' => ExtensionStatus::Active]);
            }
        });
    }
}

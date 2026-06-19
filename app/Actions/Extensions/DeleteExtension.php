<?php

namespace App\Actions\Extensions;

use App\Enums\ExtensionStatus;
use App\Enums\ProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Jobs\ProvisionSipSubscriber;
use App\Models\Extension;
use App\Models\SipProvisioningEvent;
use Illuminate\Support\Facades\DB;

class DeleteExtension
{
    public function execute(Extension $extension): void
    {
        $event = DB::transaction(function () use ($extension): SipProvisioningEvent {
            $lockedExtension = Extension::query()->lockForUpdate()->findOrFail($extension->id);
            $state = $lockedExtension->provisioningState()->lockForUpdate()->firstOrFail();
            $revision = $state->desired_revision + 1;

            $lockedExtension->update(['status' => ExtensionStatus::Disabled]);
            $lockedExtension->delete();
            $state->update([
                'desired_revision' => $revision,
                'status' => ProvisioningStatus::Pending,
                'last_error' => null,
            ]);

            return $lockedExtension->provisioningEvents()->create([
                'operation' => ProvisioningOperation::Delete,
                'revision' => $revision,
                'available_at' => now(),
            ]);
        }, attempts: 3);

        ProvisionSipSubscriber::dispatch($event->id);
    }
}

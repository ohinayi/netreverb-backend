<?php

namespace App\Actions\Extensions;

use App\Enums\ExtensionStatus;
use App\Enums\ProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Jobs\ProvisionSipSubscriber;
use App\Models\Extension;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RotateSipCredential
{
    public function execute(Extension $extension): string
    {
        [$sipPassword, $event] = DB::transaction(function () use ($extension): array {
            $lockedExtension = Extension::query()->lockForUpdate()->findOrFail($extension->id);

            if ($lockedExtension->status === ExtensionStatus::Disabled) {
                throw ValidationException::withMessages([
                    'extension' => 'A disabled extension cannot rotate credentials.',
                ]);
            }

            $credential = $lockedExtension->credential()->lockForUpdate()->firstOrFail();
            $state = $lockedExtension->provisioningState()->lockForUpdate()->firstOrFail();
            $sipPassword = Str::random(48);
            $revision = $state->desired_revision + 1;

            $credential->update([
                'password' => $sipPassword,
                'version' => $credential->version + 1,
                'rotated_at' => now(),
            ]);
            $state->update([
                'desired_revision' => $revision,
                'status' => ProvisioningStatus::Pending,
                'last_error' => null,
            ]);
            $event = $lockedExtension->provisioningEvents()->create([
                'operation' => ProvisioningOperation::Upsert,
                'revision' => $revision,
                'available_at' => now(),
            ]);

            return [$sipPassword, $event];
        }, attempts: 3);

        ProvisionSipSubscriber::dispatch($event->id);

        return $sipPassword;
    }
}

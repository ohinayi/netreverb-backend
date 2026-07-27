<?php

namespace App\Actions\Extensions;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Enums\ProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Jobs\ProvisionSipSubscriber;
use App\Models\Extension;
use App\Models\SipProvisioningEvent;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateExtension
{
    public function execute(Extension $extension, array $attributes): Extension
    {
        $event = DB::transaction(function () use ($extension, $attributes): ?SipProvisioningEvent {
            $lockedExtension = Extension::query()->lockForUpdate()->findOrFail($extension->id);
            $previousStatus = $lockedExtension->status;

            $lockedExtension->update([
                ...Arr::only($attributes, [
                    'display_name', 'type', 'status', 'unavailable_action',
                    'fallback_extension_id', 'ring_timeout_seconds',
                ]),
                ...$this->assigneeAttributes($attributes),
            ]);

            if ($lockedExtension->type === ExtensionType::Queue) {
                $lockedExtension->provisioningState()->lockForUpdate()->first()?->update([
                    'applied_revision' => 1,
                    'status' => ProvisioningStatus::Active,
                    'provisioned_at' => now(),
                    'last_error' => null,
                ]);

                return null;
            }

            if (! array_key_exists('status', $attributes) || $lockedExtension->status === $previousStatus) {
                return null;
            }

            $state = $lockedExtension->provisioningState()->lockForUpdate()->firstOrFail();
            $revision = $state->desired_revision + 1;
            $operation = $lockedExtension->status === ExtensionStatus::Active
                ? ProvisioningOperation::Upsert
                : ProvisioningOperation::Delete;

            $state->update([
                'desired_revision' => $revision,
                'status' => ProvisioningStatus::Pending,
                'last_error' => null,
            ]);

            return $lockedExtension->provisioningEvents()->create([
                'operation' => $operation,
                'revision' => $revision,
                'available_at' => now(),
            ]);
        }, attempts: 3);

        if ($event !== null) {
            ProvisionSipSubscriber::dispatch($event->id)->afterCommit();
        }

        return $extension->refresh()->load(['dialableNumber', 'user', 'fallbackExtension', 'provisioningState']);
    }

    private function assigneeAttributes(array $attributes): array
    {
        if (! array_key_exists('user_public_id', $attributes)) {
            return [];
        }

        return [
            'user_id' => $attributes['user_public_id'] === null
                ? null
                : User::query()->where('public_id', $attributes['user_public_id'])->valueOrFail('id'),
        ];
    }
}

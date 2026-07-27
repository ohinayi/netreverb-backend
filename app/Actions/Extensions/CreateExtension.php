<?php

namespace App\Actions\Extensions;

use App\Data\ExtensionCreationResult;
use App\Enums\DialableNumberType;
use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Enums\ProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Jobs\ProvisionSipSubscriber;
use App\Models\DialableNumber;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateExtension
{
    public function execute(Organization $organization, array $attributes): ExtensionCreationResult
    {
        [$extension, $sipPassword, $event] = DB::transaction(
            function () use ($organization, $attributes): array {
                $dialableNumber = DialableNumber::query()->create([
                    'organization_id' => $organization->id,
                    'realm' => config('telephony.sip_realm'),
                    'number' => $attributes['number'],
                    'type' => DialableNumberType::Extension,
                ]);

                $isQueue = $attributes['type'] === ExtensionType::Queue->value;
                $extension = $organization->extensions()->create([
                    'dialable_number_id' => $dialableNumber->id,
                    'user_id' => $this->resolveUserId(Arr::get($attributes, 'user_public_id')),
                    'display_name' => $attributes['display_name'],
                    'type' => $attributes['type'],
                    'status' => $isQueue ? ExtensionStatus::Active : ExtensionStatus::Pending,
                ]);

                $sipPassword = $isQueue ? null : Str::random(48);
                if ($sipPassword !== null) {
                    $extension->credential()->create(['password' => $sipPassword]);
                }
                $extension->provisioningState()->create([
                    'desired_revision' => 1,
                    'applied_revision' => $isQueue ? 1 : 0,
                    'status' => $isQueue ? ProvisioningStatus::Active : ProvisioningStatus::Pending,
                    'provisioned_at' => $isQueue ? now() : null,
                ]);
                $event = $isQueue ? null : $extension->provisioningEvents()->create([
                    'operation' => ProvisioningOperation::Upsert,
                    'revision' => 1,
                    'available_at' => now(),
                ]);

                return [$extension, $sipPassword, $event];
            },
            attempts: 3,
        );

        if ($event !== null) {
            ProvisionSipSubscriber::dispatch($event->id)->afterCommit();
        }

        return new ExtensionCreationResult(
            $extension->load(['dialableNumber', 'user', 'provisioningState']),
            $sipPassword,
        );
    }

    private function resolveUserId(?string $publicId): ?int
    {
        return $publicId === null
            ? null
            : User::query()->where('public_id', $publicId)->valueOrFail('id');
    }
}

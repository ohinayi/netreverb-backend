<?php

namespace App\Actions\Extensions;

use App\Data\ExtensionCreationResult;
use App\Enums\DialableNumberType;
use App\Enums\ExtensionStatus;
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

                $extension = $organization->extensions()->create([
                    'dialable_number_id' => $dialableNumber->id,
                    'user_id' => $this->resolveUserId(Arr::get($attributes, 'user_public_id')),
                    'display_name' => $attributes['display_name'],
                    'type' => $attributes['type'],
                    'status' => ExtensionStatus::Pending,
                ]);

                $sipPassword = Str::random(48);
                $extension->credential()->create(['password' => $sipPassword]);
                $extension->provisioningState()->create([
                    'desired_revision' => 1,
                    'status' => ProvisioningStatus::Pending,
                ]);
                $event = $extension->provisioningEvents()->create([
                    'operation' => ProvisioningOperation::Upsert,
                    'revision' => 1,
                    'available_at' => now(),
                ]);

                return [$extension, $sipPassword, $event];
            },
            attempts: 3,
        );

        ProvisionSipSubscriber::dispatch($event->id)->afterCommit();

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

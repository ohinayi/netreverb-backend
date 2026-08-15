<?php

namespace App\Actions\ServiceNumbers;

use App\Enums\ProvisioningStatus;
use App\Models\ServiceNumber;
use Illuminate\Support\Arr;

class UpdateServiceNumber
{
    public function execute(ServiceNumber $serviceNumber, array $attributes): ServiceNumber
    {
        $serviceNumber->update([
            ...Arr::only($attributes, ['name', 'type', 'target', 'enabled', 'configuration']),
            // Matches CreateServiceNumber: with no real DID/SIP-trunk
            // provisioning system in play (auto_activate mode), an edit has
            // nothing to wait on either - forcing Pending here left every
            // edited number stuck that way forever, since nothing ever
            // reconciles it back to Active afterward.
            'provisioning_status' => config('telephony.service_numbers.auto_activate')
                ? ProvisioningStatus::Active
                : ProvisioningStatus::Pending,
        ]);

        return $serviceNumber->load('dialableNumber');
    }
}

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
            'provisioning_status' => ProvisioningStatus::Pending,
        ]);

        return $serviceNumber->load('dialableNumber');
    }
}

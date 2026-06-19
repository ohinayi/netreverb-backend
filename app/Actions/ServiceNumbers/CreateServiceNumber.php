<?php

namespace App\Actions\ServiceNumbers;

use App\Enums\DialableNumberType;
use App\Models\DialableNumber;
use App\Models\Organization;
use App\Models\ServiceNumber;
use Illuminate\Support\Facades\DB;

class CreateServiceNumber
{
    public function execute(Organization $organization, array $attributes): ServiceNumber
    {
        return DB::transaction(function () use ($organization, $attributes): ServiceNumber {
            $dialableNumber = DialableNumber::query()->create([
                'organization_id' => $organization->id,
                'realm' => config('telephony.sip_realm'),
                'number' => $attributes['number'],
                'type' => DialableNumberType::Service,
            ]);

            return $organization->serviceNumbers()->create([
                'dialable_number_id' => $dialableNumber->id,
                'name' => $attributes['name'],
                'type' => $attributes['type'],
                'target' => $attributes['target'],
                'enabled' => $attributes['enabled'] ?? true,
                'configuration' => $attributes['configuration'] ?? null,
            ])->load('dialableNumber');
        }, attempts: 3);
    }
}

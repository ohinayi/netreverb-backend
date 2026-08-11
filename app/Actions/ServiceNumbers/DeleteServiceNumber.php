<?php

namespace App\Actions\ServiceNumbers;

use App\Models\ServiceNumber;

class DeleteServiceNumber
{
    public function execute(ServiceNumber $serviceNumber): void
    {
        $serviceNumber->update(['enabled' => false]);
        // Service numbers are soft-deleted for audit history. Release the
        // dialable value so the provider-assigned number can be reused later.
        if ($serviceNumber->dialableNumber) {
            $serviceNumber->dialableNumber->update([
                'number' => substr('deleted_'.$serviceNumber->getKey().'_'.$serviceNumber->dialableNumber->number, 0, 32),
            ]);
        }
        $serviceNumber->delete();
    }
}

<?php

namespace App\Actions\ServiceNumbers;

use App\Models\ServiceNumber;

class DeleteServiceNumber
{
    public function execute(ServiceNumber $serviceNumber): void
    {
        $serviceNumber->update(['enabled' => false]);
        $serviceNumber->delete();
    }
}

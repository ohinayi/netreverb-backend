<?php

namespace App\Actions\Extensions;

use App\Models\DialableNumber;
use App\Models\NumberSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AllocateAutomaticExtensionNumber
{
    public function execute(): string
    {
        return DB::transaction(function (): string {
            $realm = config('telephony.sip_realm');
            NumberSequence::query()->firstOrCreate(
                ['realm' => $realm],
                ['next_number' => config('telephony.automatic_extension_start')],
            );
            $sequence = NumberSequence::query()
                ->where('realm', $realm)
                ->lockForUpdate()
                ->firstOrFail();
            $end = config('telephony.automatic_extension_end');

            while ($sequence->next_number <= $end) {
                $number = (string) $sequence->next_number;
                $sequence->increment('next_number');
                $sequence->refresh();

                if (! DialableNumber::query()
                    ->where('realm', $realm)
                    ->where('number', $number)
                    ->exists()) {
                    return $number;
                }
            }

            throw new RuntimeException('The automatic SIP extension range is exhausted.');
        }, attempts: 3);
    }
}

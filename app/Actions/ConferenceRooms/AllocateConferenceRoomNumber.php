<?php

namespace App\Actions\ConferenceRooms;

use App\Models\ConferenceRoom;
use App\Models\DialableNumber;
use App\Models\NumberSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AllocateConferenceRoomNumber
{
    public function execute(): string
    {
        return DB::transaction(function (): string {
            $realm = sprintf('conference:%s', config('telephony.sip_realm'));

            NumberSequence::query()->firstOrCreate(
                ['realm' => $realm],
                ['next_number' => config('telephony.conference_number_start')],
            );

            $sequence = NumberSequence::query()
                ->where('realm', $realm)
                ->lockForUpdate()
                ->firstOrFail();
            $end = config('telephony.conference_number_end');

            while ($sequence->next_number <= $end) {
                $number = (string) $sequence->next_number;
                $sequence->increment('next_number');
                $sequence->refresh();

                if (! ConferenceRoom::query()->where('sip_number', $number)->exists()
                    && ! DialableNumber::query()
                        ->where('realm', config('telephony.sip_realm'))
                        ->where('number', $number)
                        ->exists()) {
                    return $number;
                }
            }

            throw new RuntimeException('The conference number range is exhausted.');
        }, attempts: 3);
    }
}

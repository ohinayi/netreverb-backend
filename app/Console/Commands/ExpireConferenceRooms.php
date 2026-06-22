<?php

namespace App\Console\Commands;

use App\Actions\ConferenceRooms\TouchConferenceRoomExpiry;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use Illuminate\Console\Command;

class ExpireConferenceRooms extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'conference-rooms:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire active conference rooms whose lifetime has ended.';

    public function handle(TouchConferenceRoomExpiry $touchConferenceRoomExpiry): int
    {
        $rooms = ConferenceRoom::query()
            ->where('status', ConferenceRoomStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $rooms->each(
            fn (ConferenceRoom $conferenceRoom): ConferenceRoom => $touchConferenceRoomExpiry->execute($conferenceRoom),
        );

        $this->info(sprintf('Expired %d conference room(s).', $rooms->count()));

        return self::SUCCESS;
    }
}

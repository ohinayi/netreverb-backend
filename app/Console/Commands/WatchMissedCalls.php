<?php

namespace App\Console\Commands;

use App\Services\Telephony\FreeSwitchMissedCallWatcher;
use Illuminate\Console\Command;

class WatchMissedCalls extends Command
{
    protected $signature = 'telephony:watch-missed-calls';

    protected $description = 'Watch FreeSWITCH for unanswered calls to our own extensions and record a missed-call entry even if the callee was never online to see it ring.';

    public function handle(FreeSwitchMissedCallWatcher $watcher): int
    {
        $this->components->info('Watching FreeSWITCH for missed calls. Press Ctrl+C to stop.');

        $watcher->watchForever();
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Telephony\FreeSwitchInboundCallWatcher;
use Illuminate\Console\Command;

class WatchVoipPush extends Command
{
    protected $signature = 'telephony:watch-voip-push';

    protected $description = 'Watch FreeSWITCH for inbound calls to extensions with a registered mobile device and push to ring them.';

    public function handle(FreeSwitchInboundCallWatcher $watcher): int
    {
        $this->components->info('Watching FreeSWITCH for calls to push-registered mobile extensions. Press Ctrl+C to stop.');

        $watcher->watchForever();
    }
}

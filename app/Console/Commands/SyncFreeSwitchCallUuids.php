<?php

namespace App\Console\Commands;

use App\Services\Telephony\FreeSwitchCallUuidSynchronizer;
use Illuminate\Console\Command;
use Throwable;

class SyncFreeSwitchCallUuids extends Command
{
    protected $signature = 'telephony:sync-freeswitch-call-uuids {--watch : Keep listening for FreeSWITCH events instead of running once} {--listen-seconds=60 : Event listen window when watch mode is enabled}';

    protected $description = 'Sync active FreeSWITCH channel UUIDs into call logs and start call recording.';

    public function handle(FreeSwitchCallUuidSynchronizer $synchronizer): int
    {
        if ($this->option('watch')) {
            return $this->watch($synchronizer);
        }

        try {
            $matched = $synchronizer->syncOnce();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Synced %d FreeSWITCH call(s).', $matched));

        return self::SUCCESS;
    }

    private function watch(FreeSwitchCallUuidSynchronizer $synchronizer): int
    {
        $listenSeconds = max(1, (int) $this->option('listen-seconds'));

        $this->components->info(sprintf(
            'Watching FreeSWITCH events in %d second window(s). Press Ctrl+C to stop.',
            $listenSeconds,
        ));

        while (true) {
            try {
                $matched = $synchronizer->syncFromEvents($listenSeconds);

                if ($matched > 0) {
                    $this->components->info(sprintf('Synced %d FreeSWITCH call(s).', $matched));
                }
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());
                sleep(1);
            }
        }
    }
}

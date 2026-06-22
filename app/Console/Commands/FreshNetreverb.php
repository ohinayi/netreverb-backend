<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

#[Signature('netreverb:fresh {--seed : Run the database seeders after migrating fresh}')]
#[Description('Refresh the local app database and clear Kamailio subscribers')]
class FreshNetreverb extends Command
{
    public function handle(): int
    {
        $migrateOptions = [
            '--force' => true,
        ];

        if ($this->option('seed')) {
            $migrateOptions['--seed'] = true;
        }

        $this->info('Running migrate:fresh...');
        $result = Artisan::call('migrate:fresh', $migrateOptions);

        if ($result !== self::SUCCESS) {
            $this->error('migrate:fresh failed.');

            return $result;
        }

        $this->info('Clearing Kamailio subscribers...');
        $resetResult = Artisan::call('telephony:reset-kamailio-subscribers');

        if ($resetResult !== self::SUCCESS) {
            $this->error('Kamailio subscriber cleanup failed.');

            return $resetResult;
        }

        $this->info('NetReverb fresh reset completed.');

        return self::SUCCESS;
    }
}

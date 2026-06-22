<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('telephony:reset-kamailio-subscribers')]
#[Description('Delete all SIP subscribers from the Kamailio subscriber table')]
class ResetTelephonySubscribers extends Command
{
    public function handle(): int
    {
        $deleted = DB::connection('kamailio')
            ->table('subscriber')
            ->delete();

        $this->info(sprintf('Deleted %d Kamailio subscriber row(s).', $deleted));

        return self::SUCCESS;
    }
}

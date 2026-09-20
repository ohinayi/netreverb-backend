<?php

namespace App\Console\Commands;

use App\Services\Telephony\VoicemailImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('voicemail:import')]
#[Description('Import newly recorded voicemail files into the voicemails table')]
class ImportVoicemailRecordings extends Command
{
    public function handle(VoicemailImporter $importer): int
    {
        $imported = $importer->importNew();

        $this->info(sprintf('Imported %d new voicemail%s.', $imported, $imported === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}

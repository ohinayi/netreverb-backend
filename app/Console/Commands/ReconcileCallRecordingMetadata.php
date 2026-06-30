<?php

namespace App\Console\Commands;

use App\Services\CallRecordings\CallRecordingManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('recordings:reconcile-call-metadata')]
#[Description('Orphan stale call recording metadata when local files are missing')]
class ReconcileCallRecordingMetadata extends Command
{
    public function handle(CallRecordingManager $recordingManager): int
    {
        try {
            $reconciledCount = $recordingManager->reconcileMissingFiles();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Reconciled %d stale call recording metadata entr%s.',
            $reconciledCount,
            $reconciledCount === 1 ? 'y' : 'ies',
        ));

        return self::SUCCESS;
    }
}

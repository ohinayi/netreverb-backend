<?php

namespace App\Services\CallRecordings;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class CallRecordingVpsSynchronizer
{
    /**
     * @return array{dry_run: bool, remote_host: string, remote_path: string, local_path: string}
     */
    public function sync(
        string $host,
        string $user,
        string $remoteBasePath,
        ?string $remoteRelativePath,
        bool $dryRun,
        OutputStyle $output,
    ): array {
        $localBasePath = (string) config('filesystems.disks.'.config('telephony.call_recordings.disk').'.root');

        if ($localBasePath === '') {
            throw new RuntimeException('The local call recordings disk root is not configured.');
        }

        $normalizedRelativePath = $this->normalizeRelativePath($remoteRelativePath);
        $remotePath = rtrim($remoteBasePath, '/').($normalizedRelativePath === null ? '/' : '/'.$normalizedRelativePath.'/');
        $localPath = rtrim($localBasePath, '/').($normalizedRelativePath === null ? '/' : '/'.$normalizedRelativePath.'/');

        Storage::disk(config('telephony.call_recordings.disk'))->makeDirectory($normalizedRelativePath ?? '');

        $command = [
            'rsync',
            '-avz',
            '--progress',
        ];

        if ($dryRun) {
            $command[] = '--dry-run';
        }

        $command[] = sprintf('%s@%s:%s', $user, $host, $remotePath);
        $command[] = $localPath;

        $process = new Process($command, base_path());
        $process->setTimeout(null);

        if ($this->canUseTty()) {
            $process->setTty(true);
        }

        $output->writeln(sprintf('Syncing from %s@%s:%s', $user, $host, $remotePath));
        $output->writeln(sprintf('Syncing to %s', $localPath));

        $process->run(function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) !== ''
                ? trim($process->getErrorOutput())
                : 'Recording sync failed.');
        }

        return [
            'dry_run' => $dryRun,
            'remote_host' => $host,
            'remote_path' => $remotePath,
            'local_path' => $localPath,
        ];
    }

    private function normalizeRelativePath(?string $remoteRelativePath): ?string
    {
        if ($remoteRelativePath === null) {
            return null;
        }

        $normalizedPath = trim(str_replace('\\', '/', $remoteRelativePath), '/');

        return $normalizedPath === '' ? null : $normalizedPath;
    }

    private function canUseTty(): bool
    {
        return DIRECTORY_SEPARATOR !== '\\'
            && function_exists('stream_isatty')
            && stream_isatty(STDIN);
    }
}

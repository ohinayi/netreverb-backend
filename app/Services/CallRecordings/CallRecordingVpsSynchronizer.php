<?php

namespace App\Services\CallRecordings;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
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
        ?string $password,
        bool $dryRun,
        OutputInterface $output,
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
            '-e',
            'ssh -o StrictHostKeyChecking=accept-new',
        ];

        if ($dryRun) {
            $command[] = '--dry-run';
        }

        $command[] = sprintf('%s@%s:%s', $user, $host, $remotePath);
        $command[] = $localPath;

        $processEnvironment = [];
        $askPassScript = null;

        if ($password !== null && $password !== '') {
            [$askPassScript, $processEnvironment] = $this->buildAskPassEnvironment($password);
        }

        $process = new Process($command, base_path(), $processEnvironment);
        $process->setTimeout(null);

        if ($password === null && $this->canUseTty()) {
            $process->setTty(true);
        }

        $output->writeln(sprintf('Syncing from %s@%s:%s', $user, $host, $remotePath));
        $output->writeln(sprintf('Syncing to %s', $localPath));

        try {
            $process->run(function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            });
        } finally {
            if ($askPassScript !== null && file_exists($askPassScript)) {
                @unlink($askPassScript);
            }
        }

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

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildAskPassEnvironment(string $password): array
    {
        $askPassScript = tempnam(sys_get_temp_dir(), 'netreverb-ssh-askpass-');

        if ($askPassScript === false) {
            throw new RuntimeException('Unable to create a temporary SSH askpass script.');
        }

        file_put_contents($askPassScript, "#!/bin/sh\nprintf '%s' \"\$NETREVERB_SYNC_SSH_PASSWORD\"\n");
        chmod($askPassScript, 0700);

        return [
            $askPassScript,
            [
                'DISPLAY' => 'netreverb-sync:0',
                'SSH_ASKPASS' => $askPassScript,
                'SSH_ASKPASS_REQUIRE' => 'force',
                'NETREVERB_SYNC_SSH_PASSWORD' => $password,
            ],
        ];
    }
}

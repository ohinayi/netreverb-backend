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

    /**
     * Deletes one already-synced file from the FreeSWITCH host once it's
     * old enough that the call producing it is guaranteed to have ended -
     * rsync never removes its source, so without this every recording sits
     * on the VPS disk twice (once in FreeSWITCH's own recordings folder,
     * once in the app's synced copy) forever. The age check is extra
     * insurance on top of this only ever being called after the recording
     * has already been confirmed stopped and successfully synced.
     */
    public function deleteRemoteFileIfStale(
        string $host,
        string $user,
        string $remoteFilePath,
        ?string $password,
        int $minAgeMinutes,
        OutputInterface $output,
    ): void {
        $command = [
            'ssh',
            '-o', 'StrictHostKeyChecking=accept-new',
            sprintf('%s@%s', $user, $host),
            sprintf(
                'find %s -maxdepth 0 -type f -mmin +%d -delete',
                escapeshellarg($remoteFilePath),
                $minAgeMinutes,
            ),
        ];

        $processEnvironment = [];
        $askPassScript = null;

        if ($password !== null && $password !== '') {
            [$askPassScript, $processEnvironment] = $this->buildAskPassEnvironment($password);
        }

        $process = new Process($command, base_path(), $processEnvironment);
        $process->setTimeout(30);

        try {
            $process->run();
        } finally {
            if ($askPassScript !== null && file_exists($askPassScript)) {
                @unlink($askPassScript);
            }
        }

        if (! $process->isSuccessful()) {
            $output->writeln(sprintf(
                'Could not remove synced source file %s from %s@%s: %s',
                $remoteFilePath,
                $user,
                $host,
                trim($process->getErrorOutput()),
            ));
        }
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

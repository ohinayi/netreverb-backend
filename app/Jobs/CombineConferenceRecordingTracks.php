<?php

namespace App\Jobs;

use App\Enums\ConferenceRecordingStatus;
use App\Enums\ConferenceRecordingTrackStatus;
use App\Models\ConferenceRecording;
use App\Models\ConferenceRecordingTrack;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Track Egress records each participant's mic/camera/screen-share as separate
 * raw (non-transcoded) files — that's what keeps live per-recording CPU low,
 * since nothing gets decoded or re-encoded while the call is happening. This
 * job does the actual compositing instead, offline, after the call has ended,
 * so the real encoding cost never competes with a live conference for CPU.
 */
class CombineConferenceRecordingTracks implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    private const CELL_WIDTH = 640;

    private const CELL_HEIGHT = 360;

    private const MAIN_WIDTH = 1280;

    private const MAIN_HEIGHT = 720;

    private const THUMB_WIDTH = 320;

    private const THUMB_HEIGHT = 180;

    private const THUMB_MARGIN = 16;

    private const VIDEO_KINDS = ['camera', 'screen_share'];

    private const AUDIO_KINDS = ['microphone', 'screen_share_audio'];

    public function __construct(private readonly int $recordingId) {}

    public function handle(): void
    {
        $recording = ConferenceRecording::query()->with('tracks')->find($this->recordingId);
        if ($recording === null) {
            return;
        }

        $completedTracks = $recording->tracks
            ->where('status', ConferenceRecordingTrackStatus::Completed)
            ->values();

        if ($completedTracks->isEmpty()) {
            $recording->forceFill(['status' => ConferenceRecordingStatus::Failed])->save();

            return;
        }

        $workDir = storage_path('app/tmp/recording-combine/'.$recording->recording_id);
        File::ensureDirectoryExists($workDir);
        $disk = Storage::disk('livekit_recordings');

        try {
            $videoInputs = [];
            $audioInputs = [];

            foreach ($completedTracks as $index => $track) {
                /** @var ConferenceRecordingTrack $track */
                $extension = pathinfo($track->storage_key, PATHINFO_EXTENSION) ?: 'mp4';
                $localPath = $workDir.'/track_'.$index.'.'.$extension;
                File::put($localPath, $disk->get($track->storage_key));

                // Every raw track used to be fed to ffmpeg as if it started
                // at t=0. A track that only started recording partway through
                // the call (most commonly: someone upgrades from audio-only
                // to video mid-call, so their camera track's egress begins
                // minutes after the microphone track's) was therefore
                // composited out of sync with everything else. The track
                // row's created_at is when its egress actually began, so use
                // the gap from the recording's own start as an -itsoffset.
                $offsetSeconds = max(0, $track->created_at?->getTimestamp() - $recording->created_at->getTimestamp());
                $input = ['path' => $localPath, 'offset' => $offsetSeconds, 'kind' => $track->kind];

                if (in_array($track->kind, self::VIDEO_KINDS, true)) {
                    $videoInputs[] = $input;
                } elseif (in_array($track->kind, self::AUDIO_KINDS, true)) {
                    $audioInputs[] = $input;
                }
            }

            $outputPath = $workDir.'/combined.mp4';
            $this->combine($videoInputs, $audioInputs, $outputPath);

            $disk->put($recording->storage_key, File::get($outputPath));

            $recording->forceFill([
                'status' => ConferenceRecordingStatus::Completed,
                'size' => File::size($outputPath),
                'duration' => $completedTracks->max('duration') ?? $recording->duration,
            ])->save();

            foreach ($completedTracks as $track) {
                $disk->delete(array_filter([$track->storage_key, $track->manifestStorageKey()]));
            }
        } catch (Throwable $exception) {
            Log::error('Conference recording combine failed.', [
                'recording_id' => $recording->recording_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $recording->forceFill(['status' => ConferenceRecordingStatus::Failed])->save();
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * @param  list<array{path: string, offset: int}>  $videoInputs
     * @param  list<array{path: string, offset: int}>  $audioInputs
     */
    private function combine(array $videoInputs, array $audioInputs, string $outputPath): void
    {
        $videoCount = count($videoInputs);
        $audioCount = count($audioInputs);

        if ($videoCount === 0 && $audioCount === 0) {
            throw new RuntimeException('No usable tracks to combine.');
        }

        $args = ['ffmpeg', '-y'];
        foreach ([...$videoInputs, ...$audioInputs] as $input) {
            if ($input['offset'] > 0) {
                $args[] = '-itsoffset';
                $args[] = (string) $input['offset'];
            }
            $args[] = '-i';
            $args[] = $input['path'];
        }

        $filters = [];
        $maps = [];

        if ($videoCount > 0) {
            $screenIndices = [];
            $cameraIndices = [];
            foreach ($videoInputs as $i => $input) {
                if ($input['kind'] === 'screen_share') {
                    $screenIndices[] = $i;
                } else {
                    $cameraIndices[] = $i;
                }
            }

            if ($screenIndices !== [] && ($cameraIndices !== [] || count($screenIndices) > 1)) {
                // A screen share squeezed into the same small grid cell as a
                // webcam is unreadable - give it the full frame instead and
                // ride everyone else along the bottom as small thumbnails.
                $this->buildSpotlightFilters($filters, array_shift($screenIndices), [...$screenIndices, ...$cameraIndices]);
            } elseif ($videoCount === 1) {
                $filters[] = sprintf(
                    '[0:v]scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2[vout]',
                    self::MAIN_WIDTH,
                    self::MAIN_HEIGHT,
                    self::MAIN_WIDTH,
                    self::MAIN_HEIGHT,
                );
            } else {
                $this->buildGridFilters($filters, $videoCount);
            }

            $maps[] = '[vout]';
        }

        if ($audioCount > 0) {
            $audioLabels = implode('', array_map(fn (int $i) => sprintf('[%d:a]', $videoCount + $i), range(0, $audioCount - 1)));
            if ($audioCount === 1) {
                $filters[] = "{$audioLabels}anull[aout]";
            } else {
                $filters[] = sprintf('%samix=inputs=%d:duration=longest:dropout_transition=0[aout]', $audioLabels, $audioCount);
            }

            $maps[] = '[aout]';
        }

        $args[] = '-filter_complex';
        $args[] = implode(';', $filters);
        foreach ($maps as $map) {
            $args[] = '-map';
            $args[] = $map;
        }

        if ($videoCount > 0) {
            $args[] = '-c:v';
            $args[] = 'libx264';
            $args[] = '-preset';
            $args[] = 'veryfast';
        }
        if ($audioCount > 0) {
            $args[] = '-c:a';
            $args[] = 'aac';
        }

        $args[] = $outputPath;

        $result = Process::timeout(1800)->run($args);

        if (! $result->successful()) {
            throw new RuntimeException('ffmpeg combine failed: '.$result->errorOutput());
        }
    }

    /**
     * Equal-size grid, used when there's no screen share to spotlight (just
     * one or more camera feeds).
     *
     * @param  list<string>  &$filters
     */
    private function buildGridFilters(array &$filters, int $videoCount): void
    {
        $cols = (int) ceil(sqrt($videoCount));
        $layoutPositions = [];

        for ($i = 0; $i < $videoCount; $i++) {
            $filters[] = sprintf(
                '[%d:v]scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2[v%d]',
                $i,
                self::CELL_WIDTH,
                self::CELL_HEIGHT,
                self::CELL_WIDTH,
                self::CELL_HEIGHT,
                $i,
            );
            $col = $i % $cols;
            $row = intdiv($i, $cols);
            $layoutPositions[] = ($col * self::CELL_WIDTH).'_'.($row * self::CELL_HEIGHT);
        }

        $videoLabels = implode('', array_map(fn (int $i): string => "[v{$i}]", range(0, $videoCount - 1)));
        $filters[] = sprintf(
            '%sxstack=inputs=%d:layout=%s:fill=black[vout]',
            $videoLabels,
            $videoCount,
            implode('|', $layoutPositions),
        );
    }

    /**
     * Screen share fills the full frame; everyone else rides along the
     * bottom edge as small thumbnails, right to left.
     *
     * @param  list<string>  &$filters
     * @param  list<int>  $thumbInputIndices
     */
    private function buildSpotlightFilters(array &$filters, int $mainInputIndex, array $thumbInputIndices): void
    {
        $filters[] = sprintf(
            '[%d:v]scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2[main]',
            $mainInputIndex,
            self::MAIN_WIDTH,
            self::MAIN_HEIGHT,
            self::MAIN_WIDTH,
            self::MAIN_HEIGHT,
        );

        if ($thumbInputIndices === []) {
            $filters[] = '[main]null[vout]';

            return;
        }

        $current = '[main]';
        $lastPosition = count($thumbInputIndices) - 1;

        foreach (array_values($thumbInputIndices) as $position => $inputIndex) {
            $filters[] = sprintf(
                '[%d:v]scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2[thumb%d]',
                $inputIndex,
                self::THUMB_WIDTH,
                self::THUMB_HEIGHT,
                self::THUMB_WIDTH,
                self::THUMB_HEIGHT,
                $position,
            );

            $x = self::MAIN_WIDTH - self::THUMB_MARGIN - (self::THUMB_WIDTH + self::THUMB_MARGIN) * ($position + 1);
            $y = self::MAIN_HEIGHT - self::THUMB_HEIGHT - self::THUMB_MARGIN;
            $next = $position === $lastPosition ? '[vout]' : "[stack{$position}]";

            $filters[] = sprintf('%s[thumb%d]overlay=x=%d:y=%d%s', $current, $position, $x, $y, $next);
            $current = $next;
        }
    }
}

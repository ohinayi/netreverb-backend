<?php

namespace App\Services\ConferenceRecordings;

use App\Enums\ConferenceRecordingTrackStatus;
use App\Enums\ConferenceTranscriptStatus;
use App\Models\ConferenceRecording;
use App\Models\ConferenceRecordingTrack;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class ConferenceTranscriptManager
{
    private const TRANSCRIBABLE_KINDS = ['microphone', 'screen_share_audio'];

    public function trackIsTranscribable(ConferenceRecordingTrack $track): bool
    {
        return in_array($track->kind, self::TRANSCRIBABLE_KINDS, true);
    }

    /**
     * @param  array<int, array{start_ms: int, end_ms: int|null, text: string}>  $segments
     */
    public function storeTrackSegments(ConferenceRecordingTrack $track, array $segments): void
    {
        $track->transcriptSegments()->delete();

        foreach (array_values($segments) as $index => $segment) {
            $track->transcriptSegments()->create([
                'segment_index' => $index,
                'start_ms' => $segment['start_ms'],
                'end_ms' => $segment['end_ms'],
                'text' => trim($segment['text']),
            ]);
        }
    }

    public function finalizeIfReady(ConferenceRecording $recording): void
    {
        $recording->loadMissing(['tracks.transcriptSegments', 'conferenceRoom.hostUser']);

        if (! $recording->transcription_enabled) {
            return;
        }

        if ($recording->transcript_status === ConferenceTranscriptStatus::Ready && filled($recording->transcript_file_path)) {
            return;
        }

        if ($recording->transcript_status === ConferenceTranscriptStatus::Failed) {
            return;
        }

        $eligibleTracks = $recording->tracks->filter(fn (ConferenceRecordingTrack $track): bool => $this->trackIsTranscribable($track));

        if ($eligibleTracks->isEmpty()) {
            $recording->forceFill([
                'transcript_status' => ConferenceTranscriptStatus::Failed,
                'transcript_error' => 'No audio-bearing conference tracks were available for transcription.',
                'transcript_completed_at' => now(),
            ])->save();

            return;
        }

        if ($eligibleTracks->contains(fn (ConferenceRecordingTrack $track): bool => $track->transcript_status === ConferenceTranscriptStatus::Failed)) {
            $recording->forceFill([
                'transcript_status' => ConferenceTranscriptStatus::Failed,
                'transcript_error' => 'One or more participant tracks failed transcription.',
                'transcript_completed_at' => now(),
            ])->save();

            return;
        }

        if ($eligibleTracks->contains(fn (ConferenceRecordingTrack $track): bool => $track->transcript_status !== ConferenceTranscriptStatus::Ready)) {
            $recording->forceFill([
                'transcript_status' => ConferenceTranscriptStatus::Processing,
                'transcript_error' => null,
            ])->save();

            return;
        }

        $timeline = $this->mergedTimeline($recording, $eligibleTracks);

        if ($timeline === []) {
            $recording->forceFill([
                'transcript_status' => ConferenceTranscriptStatus::Failed,
                'transcript_error' => 'The transcription engine returned no usable text.',
                'transcript_completed_at' => now(),
            ])->save();

            return;
        }

        $speakerNames = $this->speakerNamesForTimeline($timeline);
        $durationSeconds = $this->durationSeconds($recording, $timeline);
        $downloadPath = 'conferences/'.$recording->recording_id.'/transcripts/'.$recording->recording_id.'.docx';

        $recording->forceFill([
            'transcript_status' => ConferenceTranscriptStatus::Processing,
            'transcript_error' => null,
        ])->save();

        $localPath = $this->buildDocx($recording, $timeline, $speakerNames, $durationSeconds);

        $disk = Storage::disk('livekit_recordings');
        $disk->put($downloadPath, File::get($localPath));

        $recording->forceFill([
            'transcript_status' => ConferenceTranscriptStatus::Ready,
            'transcript_file_path' => $downloadPath,
            'transcript_file_name' => basename($downloadPath),
            'transcript_size' => $disk->size($downloadPath),
            'transcript_error' => null,
            'transcript_completed_at' => now(),
        ])->save();

        File::deleteDirectory(dirname($localPath));
    }

    /**
     * @param  iterable<ConferenceRecordingTrack>  $tracks
     * @return array<int, array{track_id: int, identity: string, start_ms: int, end_ms: int|null, segment_index: int, text: string}>
     */
    private function mergedTimeline(ConferenceRecording $recording, iterable $tracks): array
    {
        $timeline = [];

        foreach ($tracks as $track) {
            $offsetMs = $this->trackOffsetMs($recording, $track);

            foreach ($track->transcriptSegments->sortBy('segment_index') as $segment) {
                $text = trim((string) $segment->text);
                if ($text === '') {
                    continue;
                }

                $timeline[] = [
                    'track_id' => $track->id,
                    'identity' => $track->participant_identity,
                    'start_ms' => $offsetMs + (int) $segment->start_ms,
                    'end_ms' => $segment->end_ms !== null ? $offsetMs + (int) $segment->end_ms : null,
                    'segment_index' => (int) $segment->segment_index,
                    'text' => $text,
                ];
            }
        }

        usort($timeline, function (array $left, array $right): int {
            return [$left['start_ms'], $left['track_id'], $left['segment_index']]
                <=> [$right['start_ms'], $right['track_id'], $right['segment_index']];
        });

        return $timeline;
    }

    /**
     * @param  array<int, array{track_id: int, identity: string, start_ms: int, end_ms: int|null, segment_index: int, text: string}>  $timeline
     * @return array<string, string>
     */
    private function speakerNamesForTimeline(array $timeline): array
    {
        $publicIds = collect($timeline)
            ->pluck('identity')
            ->map(fn (string $identity): string => Str::startsWith($identity, 'user-') ? Str::after($identity, 'user-') : $identity)
            ->unique()
            ->values();

        return User::query()
            ->whereIn('public_id', $publicIds)
            ->pluck('name', 'public_id')
            ->all();
    }

    /**
     * @param  array<int, array{track_id: int, identity: string, start_ms: int, end_ms: int|null, segment_index: int, text: string}>  $timeline
     */
    private function durationSeconds(ConferenceRecording $recording, array $timeline): int
    {
        if ($recording->duration !== null) {
            return max(0, (int) $recording->duration);
        }

        $maxEndMs = collect($timeline)
            ->map(fn (array $segment): int => $segment['end_ms'] ?? ($segment['start_ms'] + 1000))
            ->max() ?? 0;

        return max(1, (int) ceil($maxEndMs / 1000));
    }

    private function trackOffsetMs(ConferenceRecording $recording, ConferenceRecordingTrack $track): int
    {
        if ($recording->created_at === null || $track->created_at === null || $track->created_at->lessThanOrEqualTo($recording->created_at)) {
            return 0;
        }

        return max(0, (int) $recording->created_at->diffInMilliseconds($track->created_at));
    }

    /**
     * @param  array<int, array{track_id: int, identity: string, start_ms: int, end_ms: int|null, segment_index: int, text: string}>  $timeline
     * @param  array<string, string>  $speakerNames
     */
    private function buildDocx(
        ConferenceRecording $recording,
        array $timeline,
        array $speakerNames,
        int $durationSeconds,
    ): string {
        $workDir = storage_path('app/tmp/conference-transcript/'.$recording->recording_id);
        File::ensureDirectoryExists($workDir);

        File::put($workDir.'/[Content_Types].xml', $this->contentTypesXml());
        File::ensureDirectoryExists($workDir.'/_rels');
        File::put($workDir.'/_rels/.rels', $this->relsXml());
        File::ensureDirectoryExists($workDir.'/word');
        File::put($workDir.'/word/document.xml', $this->buildDocumentXml($recording, $timeline, $speakerNames, $durationSeconds));

        $docxPath = $workDir.'/transcript.docx';
        $zip = new ZipArchive();
        if ($zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The transcript document could not be created.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            if ($path === $docxPath) {
                continue;
            }

            $relative = Str::of($path)
                ->replace($workDir.DIRECTORY_SEPARATOR, '')
                ->replace(DIRECTORY_SEPARATOR, '/')
                ->toString();

            $zip->addFile($path, $relative);
        }

        $zip->close();

        return $docxPath;
    }

    /**
     * @param  array<int, array{track_id: int, identity: string, start_ms: int, end_ms: int|null, segment_index: int, text: string}>  $timeline
     * @param  array<string, string>  $speakerNames
     */
    private function buildDocumentXml(
        ConferenceRecording $recording,
        array $timeline,
        array $speakerNames,
        int $durationSeconds,
    ): string {
        $roomName = $recording->conferenceRoom?->title ?: $recording->conferenceRoom?->public_id ?: 'Conference recording';
        $startedAt = $recording->created_at?->toDayDateTimeString() ?? now()->toDayDateTimeString();
        $participants = collect($timeline)
            ->pluck('identity')
            ->map(fn (string $identity): string => $this->speakerLabel($identity, $speakerNames))
            ->unique()
            ->values()
            ->all();

        $body = [];
        $body[] = $this->paragraph($roomName, true, 34);
        $body[] = $this->paragraph('Started: '.$startedAt);
        $body[] = $this->paragraph('Duration: '.$this->formatDuration($durationSeconds));
        $body[] = $this->paragraph('Participants: '.implode(', ', $participants));
        $body[] = $this->paragraph('');

        foreach ($timeline as $segment) {
            $body[] = $this->paragraph($this->speakerLabel($segment['identity'], $speakerNames).': '.$segment['text']);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.implode('', $body).'<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr></w:body>'
            .'</w:document>';
    }

    private function speakerLabel(string $identity, array $speakerNames): string
    {
        $publicId = Str::startsWith($identity, 'user-') ? Str::after($identity, 'user-') : $identity;

        return $speakerNames[$publicId] ?? $identity;
    }

    private function formatDuration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        return sprintf('%d:%02d', $minutes, $remaining);
    }

    private function paragraph(string $text, bool $bold = false, int $fontSize = 22): string
    {
        $escaped = e($text);
        $boldTag = $bold ? '<w:b/>' : '';

        return '<w:p><w:pPr><w:spacing w:after="120"/></w:pPr><w:r><w:rPr>'.$boldTag.'<w:sz w:val="'.$fontSize.'"/></w:rPr><w:t xml:space="preserve">'.$escaped.'</w:t></w:r></w:p>';
    }

    private function contentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>
XML;
    }

    private function relsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML;
    }
}

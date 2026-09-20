<?php

namespace Tests\Feature;

use App\Models\Extension;
use App\Models\Voicemail;
use App\Services\Telephony\VoicemailImporter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VoicemailImporterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function buildWavBytes(int $sampleRate, int $channels, int $bitsPerSample, float $seconds): string
    {
        $bytesPerSample = intdiv($bitsPerSample, 8);
        $dataSize = (int) round($sampleRate * $channels * $bytesPerSample * $seconds);
        $byteRate = $sampleRate * $channels * $bytesPerSample;
        $blockAlign = $channels * $bytesPerSample;

        return 'RIFF'.pack('V', 36 + $dataSize).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', $channels).pack('V', $sampleRate)
            .pack('V', $byteRate).pack('v', $blockAlign).pack('v', $bitsPerSample)
            .'data'.pack('V', $dataSize)
            .str_repeat("\x00", $dataSize);
    }

    public function test_it_imports_a_finished_recording_and_computes_its_duration(): void
    {
        Storage::fake('freeswitch_voicemails');
        config()->set('telephony.voicemail.disk', 'freeswitch_voicemails');

        $extension = Extension::factory()->create();
        $relativePath = $extension->public_id.'_20260101-000000_2348012345678.wav';
        $disk = Storage::disk('freeswitch_voicemails');
        $disk->put($relativePath, $this->buildWavBytes(8000, 1, 16, 3.0));
        touch($disk->path($relativePath), time() - 30);

        $imported = (new VoicemailImporter)->importNew();

        $this->assertSame(1, $imported);
        $voicemail = Voicemail::query()->where('file_path', $relativePath)->firstOrFail();
        $this->assertSame($extension->id, $voicemail->extension_id);
        $this->assertSame($extension->organization_id, $voicemail->organization_id);
        $this->assertSame('2348012345678', $voicemail->caller_number);
        $this->assertSame(3, $voicemail->duration_seconds);
    }

    public function test_it_skips_a_file_still_being_written(): void
    {
        Storage::fake('freeswitch_voicemails');
        config()->set('telephony.voicemail.disk', 'freeswitch_voicemails');

        $extension = Extension::factory()->create();
        $relativePath = $extension->public_id.'_20260101-000000_2348012345678.wav';
        Storage::disk('freeswitch_voicemails')->put($relativePath, $this->buildWavBytes(8000, 1, 16, 3.0));

        $imported = (new VoicemailImporter)->importNew();

        $this->assertSame(0, $imported);
        $this->assertDatabaseCount('voicemails', 0);
    }

    public function test_it_does_not_reimport_an_already_known_file(): void
    {
        Storage::fake('freeswitch_voicemails');
        config()->set('telephony.voicemail.disk', 'freeswitch_voicemails');

        $extension = Extension::factory()->create();
        $relativePath = $extension->public_id.'_20260101-000000_2348012345678.wav';
        $disk = Storage::disk('freeswitch_voicemails');
        $disk->put($relativePath, $this->buildWavBytes(8000, 1, 16, 3.0));
        touch($disk->path($relativePath), time() - 30);
        Voicemail::factory()->for($extension)->for($extension->organization)->create(['file_path' => $relativePath]);

        $imported = (new VoicemailImporter)->importNew();

        $this->assertSame(0, $imported);
        $this->assertDatabaseCount('voicemails', 1);
    }
}

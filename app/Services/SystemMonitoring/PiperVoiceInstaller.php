<?php

namespace App\Services\SystemMonitoring;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Downloads a configured-but-not-yet-installed Piper voice from the
 * project's official public voice repository. Only voices already present
 * in config('tts.piper.voices') can be installed this way - adding a
 * genuinely new voice still means adding it to config first, since that's
 * also where the app looks up which voices exist to offer for IVR use.
 */
class PiperVoiceInstaller
{
    private const REPO_BASE = 'https://huggingface.co/rhasspy/piper-voices/resolve/main';

    public function __construct(private readonly HttpFactory $http) {}

    public function install(string $voiceKey): void
    {
        $voice = (array) config("tts.piper.voices.{$voiceKey}");

        if ($voice === []) {
            throw new RuntimeException("Unknown Piper voice \"{$voiceKey}\".");
        }

        $modelPath = (string) ($voice['model'] ?? '');

        if ($modelPath === '') {
            throw new RuntimeException("Voice \"{$voiceKey}\" has no configured model path.");
        }

        [$lang, $region, $name, $quality] = $this->parseVoiceKey($voiceKey);

        File::ensureDirectoryExists(dirname($modelPath));

        $this->download("{$lang}/{$lang}_{$region}/{$name}/{$quality}/{$voiceKey}.onnx", $modelPath);
        $this->download("{$lang}/{$lang}_{$region}/{$name}/{$quality}/{$voiceKey}.onnx.json", $modelPath.'.json');
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private function parseVoiceKey(string $voiceKey): array
    {
        $parts = explode('-', $voiceKey);

        if (count($parts) !== 3 || ! str_contains($parts[0], '_')) {
            throw new RuntimeException("Voice key \"{$voiceKey}\" isn't in the expected lang_REGION-name-quality shape.");
        }

        [$lang, $region] = explode('_', $parts[0], 2);

        return [$lang, $region, $parts[1], $parts[2]];
    }

    private function download(string $remoteRelativePath, string $destination): void
    {
        $url = self::REPO_BASE.'/'.$remoteRelativePath;
        $tempPath = $destination.'.downloading';

        try {
            $response = $this->http
                ->connectTimeout(10)
                ->timeout(300)
                ->sink($tempPath)
                ->get($url);
        } catch (Throwable $exception) {
            @unlink($tempPath);

            throw new RuntimeException("Failed to download {$url}: {$exception->getMessage()}");
        }

        if (! $response->successful() || ! is_file($tempPath) || filesize($tempPath) === 0) {
            @unlink($tempPath);

            throw new RuntimeException("Failed to download {$url} (HTTP {$response->status()}).");
        }

        rename($tempPath, $destination);
    }
}

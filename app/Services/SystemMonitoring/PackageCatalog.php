<?php

namespace App\Services\SystemMonitoring;

use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Read-only view of the two "packages" an admin can currently see installed
 * on this server: LibreTranslate's loaded languages and Piper's downloaded
 * TTS voices. Downloading more Piper voices is handled by
 * PiperVoiceInstaller; LibreTranslate has no equivalent hot-add - its
 * language set is fixed at container start (--load-only), so this only
 * reports what's loaded rather than offering to add more.
 */
class PackageCatalog
{
    public function __construct(private readonly HttpFactory $http) {}

    /** @return array<string, mixed> */
    public function libreTranslate(): array
    {
        $baseUrl = rtrim((string) config('translation.providers.libretranslate.base_url'), '/');

        try {
            $response = $this->http
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->get($baseUrl.'/languages');

            if (! $response->successful()) {
                return ['reachable' => false, 'languages' => []];
            }

            $languages = collect($response->json())
                ->map(fn (array $language): array => [
                    'code' => $language['code'] ?? null,
                    'name' => $language['name'] ?? null,
                ])
                ->values()
                ->all();

            return ['reachable' => true, 'languages' => $languages];
        } catch (Throwable) {
            return ['reachable' => false, 'languages' => []];
        }
    }

    /** @return list<array<string, mixed>> */
    public function piperVoices(): array
    {
        $voices = (array) config('tts.piper.voices', []);

        return collect($voices)
            ->map(function (array $voice, string $key): array {
                $modelPath = (string) ($voice['model'] ?? '');
                $configPath = $modelPath.'.json';
                $installed = $modelPath !== '' && is_file($modelPath);

                return [
                    'key' => $key,
                    'label' => $voice['label'] ?? $key,
                    'description' => $voice['description'] ?? null,
                    'installed' => $installed,
                    'size_bytes' => $installed ? filesize($modelPath) : null,
                    'has_config' => $installed && is_file($configPath),
                ];
            })
            ->values()
            ->all();
    }
}

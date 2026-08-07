<?php

namespace App\Services\Translation;

use App\Contracts\Translation\MessageTranslationProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use RuntimeException;

class LibreTranslateMessageTranslationProvider implements MessageTranslationProvider
{
    public function __construct(private readonly HttpFactory $http) {}

    public function translate(string $text, string $targetLocale, ?string $sourceLocale = null): TranslationResult
    {
        $body = trim($text);
        if ($body === '') {
            throw new RuntimeException('The message is empty and cannot be translated.');
        }

        $targetLanguage = $this->normalizeLocale($targetLocale);
        $sourceLanguage = $sourceLocale
            ? $this->normalizeLocale($sourceLocale)
            : ($this->guessSourceLanguage($body) ?? $this->detectSourceLanguage($body));

        $response = $this->http
            ->asForm()
            ->acceptJson()
            ->connectTimeout((int) config('translation.providers.libretranslate.connect_timeout', 5))
            ->timeout((int) config('translation.providers.libretranslate.timeout', 20))
            ->retry([500, 1500], throw: false)
            ->post(rtrim((string) config('translation.providers.libretranslate.base_url'), '/').'/translate', [
                'q' => $body,
                'source' => $sourceLanguage,
                'target' => $targetLanguage,
                'format' => 'text',
                'api_key' => config('translation.providers.libretranslate.api_key'),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('LibreTranslate returned an error while translating the message.');
        }

        $translatedText = $response->json('translatedText');
        if (! is_string($translatedText) || trim($translatedText) === '') {
            throw new RuntimeException('LibreTranslate returned an invalid translation response.');
        }

        $detectedLanguage = $response->json('detectedLanguage.language');
        $resolvedSourceLanguage = is_string($detectedLanguage) && $detectedLanguage !== ''
            ? $detectedLanguage
            : ($sourceLanguage === 'auto' ? null : $sourceLanguage);

        return new TranslationResult(
            translatedText: trim($translatedText),
            sourceLanguage: $resolvedSourceLanguage,
            targetLanguage: $targetLanguage,
        );
    }

    private function normalizeLocale(string $locale): string
    {
        return Str::lower(Str::before($locale, '-'));
    }

    private function guessSourceLanguage(string $body): ?string
    {
        if (preg_match('/\p{Han}/u', $body)) {
            return 'zh';
        }

        if (preg_match('/\p{Hiragana}|\p{Katakana}/u', $body)) {
            return 'ja';
        }

        if (preg_match('/\p{Hangul}/u', $body)) {
            return 'ko';
        }

        if (preg_match('/\p{Arabic}/u', $body)) {
            return 'ar';
        }

        if (preg_match('/\p{Cyrillic}/u', $body)) {
            return 'ru';
        }

        return null;
    }

    private function detectSourceLanguage(string $body): string
    {
        $response = $this->http
            ->asForm()
            ->acceptJson()
            ->connectTimeout((int) config('translation.providers.libretranslate.connect_timeout', 5))
            ->timeout((int) config('translation.providers.libretranslate.timeout', 20))
            ->retry([500, 1500], throw: false)
            ->post(rtrim((string) config('translation.providers.libretranslate.base_url'), '/').'/detect', [
                'q' => $body,
                'api_key' => config('translation.providers.libretranslate.api_key'),
            ]);

        if (! $response->successful()) {
            return 'auto';
        }

        $candidates = $response->json();
        if (! is_array($candidates) || $candidates === []) {
            return 'auto';
        }

        $bestCandidate = $candidates[0] ?? null;
        $detectedLanguage = is_array($bestCandidate) ? ($bestCandidate['language'] ?? null) : null;
        $confidence = is_array($bestCandidate) ? ($bestCandidate['confidence'] ?? null) : null;

        if (! is_string($detectedLanguage) || $detectedLanguage === '') {
            return 'auto';
        }

        if (is_numeric($confidence) && (float) $confidence < 0.20) {
            return 'auto';
        }

        return $this->normalizeLocale($detectedLanguage);
    }
}

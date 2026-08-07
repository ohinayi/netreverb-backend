<?php

namespace App\Services\Translation;

final readonly class TranslationResult
{
    public function __construct(
        public string $translatedText,
        public ?string $sourceLanguage,
        public string $targetLanguage,
    ) {}
}

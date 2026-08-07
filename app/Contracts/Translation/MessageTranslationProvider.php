<?php

namespace App\Contracts\Translation;

use App\Services\Translation\TranslationResult;

interface MessageTranslationProvider
{
    public function translate(string $text, string $targetLocale, ?string $sourceLocale = null): TranslationResult;
}

<?php

namespace App\Enums;

enum ServiceNumberType: string
{
    case Echo = 'echo';
    case Conference = 'conference';
    case Voicemail = 'voicemail';
    case Assistant = 'assistant';
    case Ivr = 'ivr';
    case Custom = 'custom';
}

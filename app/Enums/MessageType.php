<?php

namespace App\Enums;

enum MessageType: string
{
    case Text = 'text';
    case File = 'file';
    case VoiceNote = 'voice_note';
    case System = 'system';
}

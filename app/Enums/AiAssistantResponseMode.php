<?php

namespace App\Enums;

enum AiAssistantResponseMode: string
{
    case TurnBased = 'turn_based';
    case SpeechToSpeech = 'speech_to_speech';
}

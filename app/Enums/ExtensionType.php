<?php

namespace App\Enums;

enum ExtensionType: string
{
    case User = 'user';
    case Room = 'room';
    case Queue = 'queue';
    case Assistant = 'assistant';
    case Device = 'device';
}

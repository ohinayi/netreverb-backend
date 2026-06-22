<?php

namespace App\Enums;

enum ConversationKind: string
{
    case Direct = 'direct';
    case Community = 'community';
    case Group = 'group';
}

<?php

namespace App\Enums;

enum CommunityVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case InviteOnly = 'invite_only';
}

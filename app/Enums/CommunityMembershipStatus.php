<?php

namespace App\Enums;

enum CommunityMembershipStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Left = 'left';
    case Removed = 'removed';
}

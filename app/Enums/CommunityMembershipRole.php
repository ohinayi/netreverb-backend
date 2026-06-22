<?php

namespace App\Enums;

enum CommunityMembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
}

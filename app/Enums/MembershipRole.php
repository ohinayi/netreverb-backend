<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    /** Manages extensions, service numbers, and telephony configuration. */
    case TelephonyAdmin = 'telephony_admin';
    /** Manages the members assigned to their department. */
    case DepartmentManager = 'department_manager';
    /** Can supervise live calls and review organization call activity. */
    case Supervisor = 'supervisor';
    /** Read-only access to organization call and audit activity. */
    case Auditor = 'auditor';
    case Member = 'member';
}

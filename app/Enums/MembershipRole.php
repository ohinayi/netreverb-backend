<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    /** A staff member who can handle assigned work but not administer the tenant. */
    case Agent = 'agent';
    /** Can supervise calls and operational activity in their assigned scope. */
    case Supervisor = 'supervisor';

    /** @deprecated Kept only while existing memberships are migrated to Admin. */
    /** Manages extensions, service numbers, and telephony configuration. */
    case TelephonyAdmin = 'telephony_admin';
    /** @deprecated Kept only while existing memberships are migrated to Supervisor. */
    /** Manages the members assigned to their department. */
    case DepartmentManager = 'department_manager';
    /** @deprecated Kept only while existing memberships are migrated to Agent. */
    /** Read-only access to organization call and audit activity. */
    case Auditor = 'auditor';
    /** @deprecated Kept only while existing memberships are migrated to Agent. */
    case Member = 'member';
}

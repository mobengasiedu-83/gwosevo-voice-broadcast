<?php

namespace App\Services;

class StaffRoles
{
    public const DESCRIPTIONS = [
        'Super Administrator' => 'Full platform administration, staff management and emergency broadcast controls.',
        'Administrator' => 'Manage clients, billing, contacts, campaigns and approvals. Staff and telephony changes are restricted.',
        'Telephony Administrator' => 'Configure telephony, review call reconciliation and monitor gateway health.',
        'Campaign Manager' => 'Prepare contacts, recordings and campaigns in the permitted scope.',
        'Approver' => 'Review and approve campaigns and recordings; view campaign information.',
        'Operator' => 'Operate approved broadcasts and monitor live calls and gateway health.',
        'Analyst' => 'View campaigns and reports without changing campaign configuration.',
        'Auditor' => 'Review audit information, contacts and campaigns without editing them.',
    ];
}

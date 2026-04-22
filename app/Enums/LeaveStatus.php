<?php

namespace App\Enums;

enum LeaveStatus: string
{
    case Planned          = 'Planned';
    case PendingApproval  = 'Pending Approval';
    case Approved         = 'Approved';
    case Denied           = 'Denied';
}
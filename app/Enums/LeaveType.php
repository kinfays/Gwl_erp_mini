<?php

namespace App\Enums;


enum LeaveType: string
{
    case Annual    = 'Annual';
    case Casual    = 'Casual';
    case Paternity = 'Paternity';
    case Maternity = 'Maternity';
    case Sick      = 'Sick';
}

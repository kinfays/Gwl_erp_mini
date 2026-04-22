<?php

namespace App\Enums;

enum ManagerRecommendation: string
{
    case Pending      = 'Pending';
    case Recommended  = 'Recommended';
    case Rejected     = 'Rejected';
}
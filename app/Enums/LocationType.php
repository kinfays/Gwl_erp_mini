<?php

namespace App\Enums;

enum LocationType: string
{
    case HeadOffice = 'HeadOffice';
    case Region     = 'Region';
    case District   = 'District';
}
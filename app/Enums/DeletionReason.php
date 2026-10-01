<?php

namespace App\Enums;

enum DeletionReason: string
{
    case Manual = 'manual';
    case Expired = 'expired';
}

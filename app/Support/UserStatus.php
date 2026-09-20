<?php

namespace App\Support;

enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

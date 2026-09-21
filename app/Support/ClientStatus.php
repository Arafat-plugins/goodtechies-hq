<?php

namespace App\Support;

enum ClientStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

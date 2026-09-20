<?php

namespace App\Support;

enum RoleName: string
{
    case ADMIN = 'ADMIN';
    case MANAGER = 'MANAGER';
    case EMPLOYEE = 'EMPLOYEE';
    case REMOTE_EMPLOYEE = 'REMOTE_EMPLOYEE';
    case ACCOUNTANT = 'ACCOUNTANT';
}

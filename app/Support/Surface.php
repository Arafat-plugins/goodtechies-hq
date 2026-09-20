<?php

namespace App\Support;

/**
 * The three separate UI shells. Each role reaches exactly one.
 */
enum Surface: string
{
    case Admin = 'admin';
    case Employee = 'employee';
    case Accountant = 'accountant';

    public function homeRoute(): string
    {
        return match ($this) {
            self::Admin => 'admin.dashboard',
            self::Employee => 'employee.dashboard',
            self::Accountant => 'accountant.dashboard',
        };
    }

    /**
     * MANAGER is a dormant role and gets the least-privileged shell (Employee).
     */
    public static function forRole(?RoleName $role): ?self
    {
        return match ($role) {
            RoleName::ADMIN => self::Admin,
            RoleName::EMPLOYEE, RoleName::REMOTE_EMPLOYEE, RoleName::MANAGER => self::Employee,
            RoleName::ACCOUNTANT => self::Accountant,
            null => null,
        };
    }
}

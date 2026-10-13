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
     * Polish 065: where `/` (and so every sign-in) lands. Admins and employees start on My
     * Attendance — the clock-in screen — and reach the dashboard from the sidebar; the
     * Accountant keeps the dashboard. `homeRoute()` stays the shell's home for links such as an
     * error page's "Go to my home page".
     */
    public function landingRoute(): string
    {
        return match ($this) {
            self::Admin, self::Employee => 'attendance.show',
            self::Accountant => $this->homeRoute(),
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

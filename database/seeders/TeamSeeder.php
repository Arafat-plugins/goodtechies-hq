<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use App\Support\RoleName;
use App\Support\TrackingMode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class TeamSeeder extends Seeder
{
    private const WORKING_DAYS = ['sun', 'mon', 'tue', 'wed', 'thu'];

    /**
     * Seed the five team members with their employee records and schedules.
     * Existing users are kept as they are; their employee record and schedule are brought in line.
     */
    public function run(): void
    {
        $password = env('SEED_PASSWORD');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('SEED_PASSWORD is empty. Set it in .env before seeding; seeded passwords are never hard-coded.');
        }

        $secret = env('SEED_TWO_FACTOR_SECRET');
        $twoFactorSecret = app()->environment('local', 'testing') && is_string($secret) && $secret !== ''
            ? $secret
            : null;

        foreach ($this->team() as $member) {
            $user = User::firstOrCreate(
                ['email' => $member['email']],
                ['name' => $member['name'], 'password' => $password],
            );

            if ($user->wasRecentlyCreated && $twoFactorSecret !== null && $member['two_factor']) {
                $user->forceFill([
                    'two_factor_secret' => $twoFactorSecret,
                    'two_factor_recovery_codes' => $this->hashedRecoveryCodes(),
                    'two_factor_confirmed_at' => now(),
                ])->save();
            }

            $employee = Employee::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'employee_number' => $member['employee_number'],
                    'role_id' => Role::where('name', $member['role']->value)->firstOrFail()->id,
                    'tracking_mode' => $member['tracking_mode'],
                ],
            );

            if ($member['schedule'] !== null) {
                Schedule::updateOrCreate(['employee_id' => $employee->id], $member['schedule']);
            }
        }
    }

    /**
     * @return list<array{name: string, email: string, employee_number: string, role: RoleName, tracking_mode: TrackingMode, two_factor: bool, schedule: array<string, mixed>|null}>
     */
    private function team(): array
    {
        $office = [
            'working_days' => self::WORKING_DAYS,
            'working_hours_per_day' => '8.00',
            'start_time' => '09:00',
            'office_or_remote' => 'office',
        ];

        return [
            [
                'name' => 'Shahadat Hossain',
                'email' => env('SEED_SHAHADAT_EMAIL') ?: 'shahadat@goodtechies.test',
                'employee_number' => 'GT-001',
                'role' => RoleName::ADMIN,
                'tracking_mode' => TrackingMode::OfficeAttendance,
                'two_factor' => true,
                'schedule' => $office,
            ],
            [
                'name' => 'Faruk Ahmed',
                'email' => env('SEED_FARUK_EMAIL') ?: 'faruk@goodtechies.test',
                'employee_number' => 'GT-002',
                'role' => RoleName::ADMIN,
                'tracking_mode' => TrackingMode::OfficeAttendance,
                'two_factor' => true,
                'schedule' => $office,
            ],
            [
                'name' => 'Tapu',
                'email' => env('SEED_TAPU_EMAIL') ?: 'tapu@goodtechies.test',
                'employee_number' => 'GT-003',
                'role' => RoleName::REMOTE_EMPLOYEE,
                'tracking_mode' => TrackingMode::RemoteTimer,
                'two_factor' => false,
                'schedule' => [
                    'working_days' => self::WORKING_DAYS,
                    'working_hours_per_day' => '5.00',
                    'start_time' => null,
                    'office_or_remote' => 'remote',
                ],
            ],
            [
                'name' => 'Yaseen',
                'email' => env('SEED_YASEEN_EMAIL') ?: 'yaseen@goodtechies.test',
                'employee_number' => 'GT-004',
                'role' => RoleName::EMPLOYEE,
                'tracking_mode' => TrackingMode::OfficeAttendance,
                'two_factor' => false,
                'schedule' => $office,
            ],
            [
                'name' => 'Accountant',
                'email' => env('SEED_ACCOUNTANT_EMAIL') ?: 'accountant@goodtechies.test',
                'employee_number' => 'GT-005',
                'role' => RoleName::ACCOUNTANT,
                'tracking_mode' => TrackingMode::None,
                'two_factor' => true,
                'schedule' => null,
            ],
        ];
    }

    /**
     * Eight random recovery codes, stored hashed (the column itself is encrypted).
     *
     * @return list<string>
     */
    private function hashedRecoveryCodes(): array
    {
        return array_map(fn (): string => Hash::make(Str::random(10)), range(1, 8));
    }
}

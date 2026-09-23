<?php

namespace App\Models;

use App\Support\Permission;
use App\Support\RoleName;
use App\Support\TrackingMode;
use App\Support\UserStatus;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'employee_number',
    'role_id',
    'manager_id',
    'phone',
    'employment_type',
    'tracking_mode',
    'joining_date',
    'status',
])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tracking_mode' => TrackingMode::class,
            'status' => UserStatus::class,
            'joining_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * @return HasOne<Schedule, $this>
     */
    public function schedule(): HasOne
    {
        return $this->hasOne(Schedule::class);
    }

    /**
     * @return HasMany<AttendanceRecord, $this>
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * Employees whose attendance this user may read.
     *
     * The ONE statement of the rule (decision 2-37), asked of a set here and of one subject in
     * `AttendanceRecordPolicy::viewFor()`, which calls into it. Three cases and no fourth:
     *
     *   - nobody, for a signed-out or deactivated user;
     *   - everybody, for a holder of `attendance.manage_others` who manages everybody (an
     *     Admin) — and their own team for one who does not (a Manager, Part C's 🟡 cell);
     *   - themselves, for a holder of `attendance.view_own`, which is every role.
     *
     * The permission comes first and the role never appears. It is a scope rather than a
     * policy call because that is what makes somebody else's day ABSENT instead of refused: a
     * list narrowed by it simply does not contain them, and a lookup by id comes back empty,
     * so 404 is what the controller has rather than what it decides (Part C).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAttendanceVisibleTo(Builder $query, ?User $user): void
    {
        if ($user === null || ! $user->isActive()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $own = $user->employee?->getKey();

        if (! $user->hasPermission(Permission::AttendanceManageOthers)) {
            // `attendance.view_own` is held by every seeded role, but it is still asked: a
            // role that lost it must lose the page, not keep it because everybody else has it.
            $query->where('employees.id', $user->hasPermission(Permission::AttendanceViewOwn) ? $own : null);

            return;
        }

        // Manage-others, scoped. An Admin manages everybody; a Manager manages the people who
        // report to them, plus themselves. The scope is the check, never a second permission
        // key (Part C §1).
        if ($user->hasRole(RoleName::ADMIN)) {
            return;
        }

        $query->where(function (Builder $scoped) use ($own): void {
            $scoped->where('employees.manager_id', $own)->orWhere('employees.id', $own);
        });
    }

    /**
     * Everybody whose day the roster has something to say about.
     *
     * `tracking_mode` and not the role: an employee who is tracked by neither the office clock
     * nor the remote timer has no attendance to report, and the Accountant is exactly that
     * today. Reading it off `tracking_mode` means the day somebody's tracking changes, the
     * roster follows without a list of role names being edited (decisions 2-28, 2-31).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeTracked(Builder $query): void
    {
        // Qualified, because the roster and the schedule editor both join `users` to order by
        // name and both tables carry a `status`.
        $query
            ->whereIn('employees.tracking_mode', [TrackingMode::OfficeAttendance->value, TrackingMode::RemoteTimer->value])
            ->where('employees.status', UserStatus::Active->value);
    }

    /**
     * Projects the employee is a member of.
     *
     * @return BelongsToMany<Project, $this, ProjectMember>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->withPivot('role_on_project')
            ->withTimestamps();
    }

    /**
     * Projects the employee is the PM for.
     *
     * @return HasMany<Project, $this>
     */
    public function managedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'pm_id');
    }
}

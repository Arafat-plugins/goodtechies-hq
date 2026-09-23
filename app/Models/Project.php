<?php

namespace App\Models;

use App\Support\BillingType;
use App\Support\Permission;
use App\Support\Priority;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use App\Support\RoleName;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'client_id',
    'name',
    'domain',
    'project_type',
    'billing_type',
    'start_date',
    'deadline',
    'status',
    'priority',
    'pm_id',
    'internal_notes',
    'employee_notes',
    'archived_at',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_type' => ProjectType::class,
            'billing_type' => BillingType::class,
            'status' => ProjectStatus::class,
            'priority' => Priority::class,
            'start_date' => 'date',
            'deadline' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasOne<ProjectFinance, $this>
     */
    public function finance(): HasOne
    {
        return $this->hasOne(ProjectFinance::class);
    }

    /**
     * @return BelongsToMany<Employee, $this, ProjectMember>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'project_members')
            ->withPivot('role_on_project')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function pm(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'pm_id');
    }

    /**
     * The project's Files tab (spec §7) — current versions only, newest first, like a task's.
     *
     * @return HasMany<File, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class)->whereNull('superseded_at')->orderByDesc('id');
    }

    /**
     * Every task on this project.
     *
     * Unscoped on purpose — it is the relation, not a view. Who may SEE these is
     * Task::visibleTo()'s question and is asked by whoever is listing them; the one caller in
     * this phase is ProjectService::changeStatus(), counting what a cancellation leaves behind
     * for somebody to deal with, and that count must not depend on who did the cancelling.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::Active->value);
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Projects the employee is a member of, or the PM for.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeForEmployee(Builder $query, Employee $employee): Builder
    {
        return $query->where(function (Builder $q) use ($employee) {
            $q->where('pm_id', $employee->id)
                ->orWhereHas('members', function (Builder $members) use ($employee) {
                    $members->where('employees.id', $employee->id);
                });
        });
    }

    /**
     * The projects the user may see at all (ProjectPolicy::view, as a query). Anyone without
     * projects.view — an Accountant, an inactive user — sees none, so list endpoints never
     * have to filter the result afterwards.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isActive() || ! $user->hasPermission(Permission::ProjectsView)) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole(RoleName::ADMIN, RoleName::MANAGER)) {
            return $query;
        }

        $employee = $user->employee;

        return $employee === null
            ? $query->whereRaw('1 = 0')
            : $query->forEmployee($employee);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}

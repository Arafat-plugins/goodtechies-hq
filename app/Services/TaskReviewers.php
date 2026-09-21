<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who reviews a task: the project's PM, and if the project has no PM, every Admin.
 *
 * One class, because the rule has three callers that must not be allowed to drift — TaskPolicy
 * (may this person approve?), TaskService (who gets named in the activity line?) and, in
 * slice 5, the notification that says "your task is waiting for review". A copy of this rule in
 * a notification class is a copy that can be wrong.
 *
 * `isReviewer()` is the same question `for()` answers, asked about one person; it is separate
 * because the List view asks it once per task payload and must not run a query to do it.
 */
class TaskReviewers
{
    /**
     * Everyone who may pass a review verdict on this task, as users. Slice 5's "notify the
     * reviewer" reads exactly this.
     *
     * @return Collection<int, User>
     */
    public function for(Task $task): Collection
    {
        $pmId = $task->project?->pm_id;

        if ($pmId === null) {
            return $this->admins();
        }

        /** @var Collection<int, User> $pm */
        $pm = User::query()
            ->whereHas('employee', fn (Builder $employee) => $employee->whereKey($pmId))
            ->where('status', UserStatus::Active->value)
            ->get();

        return $pm;
    }

    /**
     * Is this user a reviewer of this task? The question TaskPolicy::review() asks.
     */
    public function isReviewer(User $user, Task $task): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        $pmId = $task->project?->pm_id;

        return $pmId === null
            ? $user->hasRole(RoleName::ADMIN)
            : $user->employee?->id === $pmId;
    }

    /**
     * @return Collection<int, User>
     */
    private function admins(): Collection
    {
        /** @var Collection<int, User> $admins */
        $admins = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('employee.role', fn (Builder $role) => $role->where('name', RoleName::ADMIN->value))
            ->get();

        return $admins;
    }
}

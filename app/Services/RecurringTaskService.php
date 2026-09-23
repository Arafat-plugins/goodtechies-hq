<?php

namespace App\Services;

use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\User;
use App\Support\RecurrenceRule;
use App\Support\RecurrenceSummary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Retainer templates: setting one up, editing it, and listing a project's.
 *
 * It owns the write side of the templates SCREEN and nothing about generation. Running a
 * template is `RecurringTaskEngine`, which is also what "Generate now" calls — this class has no
 * generate() of its own, because a second way to make an instance is exactly what decisions 2-9,
 * 2-36 and this phase's engine all refused. The controller calls the engine directly, with
 * `force: true`, which is the entry point the engine left for that button.
 *
 * Validation is in the Form Requests. Authorization is in `RecurringTaskPolicy`; this class asks
 * the gate and turns a refusal into an exception, so a console caller is refused exactly the way
 * an HTTP request is — the shape `TagService` and `TaskService` already have.
 *
 * ## Why `next_run_at` is written here
 *
 * The column is a preview the engine refreshes after every attempt. A template that has just
 * been created, or whose rule has just been edited, has not had an attempt yet — so without
 * this it would carry no preview, or last week's rule's preview, until the scheduler next ran.
 * `refreshNextRunAt()` is the model's own method and the engine's own call; this is the same
 * call at the other moment the rule changes, not a second implementation of it.
 */
class RecurringTaskService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * A project's templates, newest first, with everything a row prints.
     *
     * `log` is eager-loaded and then read to its first element for the "last outcome" column.
     * The relation is already ordered newest-first, so this is one query for every template's
     * whole history rather than a query per row — which is the right trade at three templates
     * and would not be at three hundred. If a project ever has three hundred retainers, this is
     * the line to change.
     *
     * @return Collection<int, RecurringTask>
     */
    public function forProject(Project $project): Collection
    {
        return RecurringTask::query()
            ->where('project_id', $project->getKey())
            ->with([
                'project',
                'defaultAssignee.user',
                'log' => fn (HasMany $log) => $log->limit(1),
                'log.task',
                'log.previousOpenTask',
            ])
            ->orderByDesc('active')
            ->orderBy('id')
            ->get();
    }

    /**
     * Set a template up.
     *
     * @param  array{title_template: string, checklist_template: list<string>, default_assignee_id: int|null, active: bool}  $attributes
     *
     * @throws AuthorizationException
     */
    public function create(User $actor, Project $project, array $attributes, RecurrenceRule $rule): RecurringTask
    {
        if (! Gate::forUser($actor)->allows('create', [RecurringTask::class, $project])) {
            throw new AuthorizationException('You are not allowed to set up recurring tasks on this project.');
        }

        return DB::transaction(function () use ($actor, $project, $attributes, $rule): RecurringTask {
            $template = new RecurringTask([
                ...$attributes,
                'project_id' => $project->getKey(),
                // The first candidate for the ACTOR the engine creates instances as, which is
                // why it is set at birth and never re-stamped by an edit: the generated task's
                // `created_by` is the agency's "original assigner", and that is the person who
                // set the retainer up, not whoever last fixed a typo in its checklist.
                'created_by' => $actor->getKey(),
            ]);

            $template->setRule($rule)->save();
            $template->refreshNextRunAt(Carbon::today());

            // Recorded against the PROJECT, so it lands on the project's own Activity tab
            // beside the status changes and the membership edits. A template is a fact about
            // the engagement.
            $this->activity->record($project, sprintf(
                'Recurring template created: %s (%s)',
                $template->title_template,
                RecurrenceSummary::cadence($rule),
            ), $actor);

            return $template->refresh();
        });
    }

    /**
     * Edit a template: its title, its checklist, its rule, its assignee or its switch.
     *
     * The project is not editable — see UpdateRecurringTaskRequest. Neither is `created_by`.
     *
     * @param  array{title_template: string, checklist_template: list<string>, default_assignee_id: int|null, active: bool}  $attributes
     *
     * @throws AuthorizationException
     */
    public function update(User $actor, RecurringTask $template, array $attributes, RecurrenceRule $rule): RecurringTask
    {
        if (! Gate::forUser($actor)->allows('update', $template)) {
            throw new AuthorizationException('You are not allowed to edit this recurring task.');
        }

        return DB::transaction(function () use ($actor, $template, $attributes, $rule): RecurringTask {
            $wasActive = (bool) $template->active;

            $template->fill($attributes)->setRule($rule)->save();
            $template->refreshNextRunAt(Carbon::today());

            // The switch gets a line of its own, because it is the one edit whose effect is
            // that nothing happens: a month with no maintenance task and no explanation is the
            // thing this whole phase exists to prevent, so "who turned it off, and when" is
            // written down where somebody looking at the project will find it.
            if ($wasActive !== (bool) $template->active) {
                $this->activity->record($template->project, sprintf(
                    'Recurring template %s: %s',
                    $template->active ? 'switched on' : 'switched off',
                    $template->title_template,
                ), $actor);
            } else {
                $this->activity->record($template->project, sprintf(
                    'Recurring template edited: %s (%s)',
                    $template->title_template,
                    RecurrenceSummary::cadence($rule),
                ), $actor);
            }

            return $template->refresh();
        });
    }
}

<?php

namespace App\Services;

use App\Exceptions\ProjectStateException;
use App\Models\Project;
use App\Models\ProjectEarningEntry;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\ProjectEarningKind;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Hand-recorded project financials: contract values, earned amounts and amounts received.
 *
 * These entries are a record of the project only. Recording, correcting or deleting one never
 * creates, changes or removes an income, expense or payroll row — the admin keeps the Income
 * module separately, so the same money is never counted twice. Every write is audited with the
 * full row, which is what makes the hard delete safe.
 */
class ProjectEarningService
{
    /** Everything a caller may write here. */
    private const FIELDS = ['kind', 'amount', 'currency', 'date', 'description'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    public function record(User $actor, Project $project, array $attributes): ProjectEarningEntry
    {
        $this->guard($actor, $project, 'given earnings');

        $values = $this->values($attributes);

        return DB::transaction(function () use ($actor, $project, $values): ProjectEarningEntry {
            $entry = new ProjectEarningEntry($values);
            $entry->project_id = (int) $project->getKey();
            $entry->recorded_by = (int) $actor->getKey();
            $entry->save();
            $entry->refresh();

            $this->audit->record(AuditEvent::ProjectEarningRecorded, $entry, null, $entry->auditValues(), $actor);
            $this->activity->record($project, 'Project earning recorded: '.$this->summary($entry), $actor);

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    public function update(User $actor, ProjectEarningEntry $entry, array $attributes): ProjectEarningEntry
    {
        $project = $entry->project()->firstOrFail();
        $this->guard($actor, $project, 'given earnings');

        $values = $this->values($attributes);

        return DB::transaction(function () use ($actor, $project, $entry, $values): ProjectEarningEntry {
            $old = $entry->fresh()?->auditValues();

            $entry->fill($values);
            $entry->updated_by = (int) $actor->getKey();
            $entry->save();
            $entry->refresh();

            $this->audit->record(AuditEvent::ProjectEarningEdited, $entry, $old, $entry->auditValues(), $actor);
            $this->activity->record($project, 'Project earning edited: '.$this->summary($entry), $actor);

            return $entry;
        });
    }

    /**
     * A hard delete; the audit row keeps the full row.
     *
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    public function delete(User $actor, ProjectEarningEntry $entry): void
    {
        $project = $entry->project()->firstOrFail();
        $this->guard($actor, $project, 'stripped of its earnings');

        DB::transaction(function () use ($actor, $project, $entry): void {
            $old = $entry->fresh()?->auditValues() ?? $entry->auditValues();
            $type = $entry->getMorphClass();
            $id = (int) $entry->getKey();
            $summary = $this->summary($entry);

            $entry->delete();

            $this->audit->recordFor(AuditEvent::ProjectEarningDeleted, $type, $id, $old, null, $actor);
            $this->activity->record($project, 'Project earning deleted: '.$summary, $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function values(array $attributes): array
    {
        $values = array_intersect_key($attributes, array_flip(self::FIELDS));

        if (array_key_exists('amount', $values) && $this->toCents((string) $values['amount']) <= 0) {
            throw new InvalidArgumentException('An amount must be greater than zero.');
        }

        return $values;
    }

    private function summary(ProjectEarningEntry $entry): string
    {
        $kind = $entry->kind instanceof ProjectEarningKind ? $entry->kind->label() : (string) $entry->kind;

        return $kind.' '.$entry->amount.' '.$entry->currency;
    }

    private function toCents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * The same rule as ProjectFinanceService::guard().
     *
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    private function guard(User $actor, Project $project, string $action): void
    {
        if ($project->isArchived()) {
            throw ProjectStateException::archived($action);
        }

        if (! Gate::forUser($actor)->allows('updateFinance', $project)) {
            throw new AuthorizationException('You are not allowed to change this project\'s finances.');
        }
    }
}

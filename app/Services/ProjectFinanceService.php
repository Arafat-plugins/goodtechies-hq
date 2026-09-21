<?php

namespace App\Services;

use App\Exceptions\ProjectStateException;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Models\User;
use App\Support\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The money on a project. Kept apart from ProjectService because the permission to change it
 * is a different one (master prompt Part B §3 rule 7): a caller that may edit a project does
 * not automatically get to touch its price.
 *
 * Every price move is audited, because a changed price is the one project field the client is
 * billed on.
 */
class ProjectFinanceService
{
    /** The money fields whose movement is audited. */
    private const AUDITED_FIELDS = ['price', 'recurring_amount'];

    /** Everything a caller may write here. */
    private const FIELDS = [
        'price',
        'recurring_amount',
        'billing_frequency',
        'contract_value',
        'contract_terms',
        'profitability_snapshot',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Create or update the project's finance row.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    public function upsert(User $actor, Project $project, array $data): ProjectFinance
    {
        $this->guard($actor, $project, 'given finance');

        $attributes = array_intersect_key($data, array_flip(self::FIELDS));

        return DB::transaction(function () use ($actor, $project, $attributes): ProjectFinance {
            $finance = $project->finance()->first();
            $existed = $finance !== null;
            $old = $existed ? $this->auditedValues($finance) : null;

            if ($existed) {
                $finance->fill($attributes)->save();
            } else {
                $finance = $project->finance()->create($attributes);
            }

            $finance->refresh();

            $this->recordPriceChange($actor, $project, $old, $this->auditedValues($finance), $existed);
            $this->activity->record($project, 'Project finance updated', $actor);

            return $finance;
        });
    }

    /**
     * Drop the project's finance row. The removal is audited like any other price move.
     *
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    public function forget(User $actor, Project $project): void
    {
        $this->guard($actor, $project, 'stripped of its finance');

        DB::transaction(function () use ($actor, $project): void {
            $finance = $project->finance()->first();

            if ($finance === null) {
                return;
            }

            $old = $this->auditedValues($finance);
            $finance->delete();
            $project->unsetRelation('finance');

            $this->audit->record(AuditEvent::ProjectPriceChanged, $project, $old, null, $actor);
            $this->activity->record($project, 'Project finance removed', $actor);
        });
    }

    /**
     * A price move is audited when it moves an existing row, or when it is the first row on a
     * project that already existed. The first row written as part of creating the project is
     * covered by the project.created entry instead; wasRecentlyCreated is what tells the two
     * apart, so a project resolved from the database always counts as pre-existing.
     *
     * @param  array<string, string|null>|null  $old
     * @param  array<string, string|null>  $new
     */
    private function recordPriceChange(User $actor, Project $project, ?array $old, array $new, bool $existed): void
    {
        if (! $existed) {
            if ($project->wasRecentlyCreated) {
                return;
            }

            $this->audit->record(AuditEvent::ProjectPriceChanged, $project, null, $new, $actor);

            return;
        }

        $changed = array_keys(array_filter(
            $new,
            fn (?string $value, string $field): bool => $value !== ($old[$field] ?? null),
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed === []) {
            return;
        }

        $this->audit->record(
            AuditEvent::ProjectPriceChanged,
            $project,
            array_intersect_key($old ?? [], array_flip($changed)),
            array_intersect_key($new, array_flip($changed)),
            $actor,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function auditedValues(ProjectFinance $finance): array
    {
        $values = [];

        foreach (self::AUDITED_FIELDS as $field) {
            $value = $finance->getAttribute($field);
            $values[$field] = $value === null ? null : (string) $value;
        }

        return $values;
    }

    /**
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    private function guard(User $actor, Project $project, string $action): void
    {
        if ($project->isArchived()) {
            throw ProjectStateException::archived($action);
        }

        if (! Gate::forUser($actor)->allows('updateFinance', $project)) {
            throw new AuthorizationException('You are not allowed to change this project\'s finance.');
        }
    }
}

<?php

namespace App\Support;

/**
 * What one attempt at one template for one period did — `recurring_generation_log.outcome`.
 *
 * The master prompt names three (`generated | skipped_duplicate | skipped_inactive`). Two more
 * are here because the engine can refuse for two more reasons, and **a refusal that is not
 * written down is a silent one**: Part D §21 requires a cancelled project to stop generating,
 * and an agency that cannot see WHY October's task never appeared has been told nothing at all.
 *
 * Every attempt writes exactly one of these. There is no sixth outcome for "nothing happened",
 * because an attempt that was never made writes no row — see RecurringTaskEngine::due().
 */
enum GenerationOutcome: string
{
    /** The instance was created. `task_id` points at it. */
    case Generated = 'generated';

    /**
     * This period already had an instance. Either the engine's own check found it, or — when two
     * runs raced past that check in the same second — the unique index refused the insert. The
     * two cases are the same outcome on purpose: from the log's point of view nothing happened
     * twice, and which of the two guards caught it is not a fact anybody needs.
     */
    case SkippedDuplicate = 'skipped_duplicate';

    /** The template's `active` toggle is off. Only reachable through a forced "Generate now". */
    case SkippedInactive = 'skipped_inactive';

    /** The project is cancelled, archived or otherwise no longer open — see §21. */
    case SkippedProjectClosed = 'skipped_project_closed';

    /**
     * Nobody is left who may create a task on this project: the template's author, the PM and
     * every Admin are gone or deactivated. Vanishingly rare, and exactly the sort of thing that
     * must not fail quietly at five past midnight.
     */
    case SkippedNoActor = 'skipped_no_actor';

    public function label(): string
    {
        return match ($this) {
            self::Generated => 'Generated',
            self::SkippedDuplicate => 'Skipped — already generated',
            self::SkippedInactive => 'Skipped — template inactive',
            self::SkippedProjectClosed => 'Skipped — project closed',
            self::SkippedNoActor => 'Skipped — nobody may create it',
        };
    }

    /**
     * Whether the templates screen should show this row as a warning rather than as history.
     *
     * Every skip is a warning: the spec asks for "generation log with duplicate warnings", and
     * the four reasons a period produced nothing are all things somebody should look at once.
     */
    public function isWarning(): bool
    {
        return $this !== self::Generated;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $outcome): string => $outcome->value, self::cases());
    }
}

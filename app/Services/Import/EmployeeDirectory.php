<?php

namespace App\Services\Import;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Turns the text in an export's assignee column into an employee of this company, or into a
 * reported miss.
 *
 * **It never creates anybody.** Phase 12 rule 4, and the reason is not tidiness: a user created
 * by an import has no role, no schedule, no tracking mode and no password, and the first thing
 * anyone would do with it is grant it something. A name this company does not have is a line in
 * the report — "`Sadia` matches no employee, the task was imported unassigned" — which somebody
 * reads before cutover and either fixes in the export or by adding the person properly.
 *
 * ## What counts as a match
 *
 * In order, and the first that hits wins:
 *   1. the user's e-mail, exactly (case-insensitively). Asana exports `Assignee Email`, and an
 *      address is the only identifier in either export that is actually unique.
 *   2. the user's name, exactly (case-insensitively). ClickUp exports a display name.
 *   3. the local part of an e-mail against a name — `tapu@…` against `Tapu` — which is how the
 *      client's own workspace spells the one person in it.
 *
 * **Ambiguity is a miss, not a coin toss.** Two employees called "Tapu" mean the export cannot
 * say which one, and picking the lower id would assign somebody's year of work to the wrong
 * person quietly. It is reported with both candidates named.
 *
 * The whole directory is loaded once per run: a per-row query over a few hundred rows is a few
 * hundred queries, and this table has five rows in it.
 */
final class EmployeeDirectory
{
    /** @var Collection<int, Employee>|null */
    private ?Collection $employees = null;

    /** @var array<string, list<Employee>> */
    private array $byKey = [];

    /**
     * The employee this text names, or null. `$why` is filled with the reason for a null so the
     * caller can report it without repeating the rules.
     */
    public function find(string $text, ?string &$why = null): ?Employee
    {
        $this->load();

        $key = self::key($text);

        if ($key === '') {
            $why = 'the assignee cell is empty';

            return null;
        }

        $candidates = $this->byKey[$key] ?? [];

        if ($candidates === []) {
            $why = sprintf('"%s" matches no employee — nobody was created and the task is unassigned', $text);

            return null;
        }

        if (count($candidates) > 1) {
            $why = sprintf(
                '"%s" matches %d employees (%s) — the export cannot say which, so the task is unassigned',
                $text,
                count($candidates),
                implode(', ', array_map(
                    fn (Employee $one): string => sprintf('%s <%s>', $one->user?->name, $one->user?->email),
                    $candidates,
                )),
            );

            return null;
        }

        $why = null;

        return $candidates[0];
    }

    private function load(): void
    {
        if ($this->employees !== null) {
            return;
        }

        $this->employees = Employee::query()->with('user')->orderBy('id')->get();

        foreach ($this->employees as $employee) {
            $user = $employee->user;

            if ($user === null) {
                continue;
            }

            $email = (string) $user->email;
            $keys = [self::key($email), self::key((string) $user->name)];

            // `tapu@goodtechies.test` also answers to `Tapu`, and vice versa.
            if (str_contains($email, '@')) {
                $keys[] = self::key(strstr($email, '@', true) ?: '');
            }

            foreach (array_unique(array_filter($keys)) as $key) {
                $this->byKey[$key][] = $employee;
            }
        }

        // The same person reached through two of their own keys is still one candidate.
        foreach ($this->byKey as $key => $list) {
            $this->byKey[$key] = array_values(
                (new Collection($list))->unique(fn (Employee $one): int => (int) $one->getKey())->all(),
            );
        }
    }

    private static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}

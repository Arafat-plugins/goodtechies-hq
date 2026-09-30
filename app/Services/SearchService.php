<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\File;
use App\Models\Income;
use App\Models\Meeting;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Permission;
use App\Support\RoleName;
use App\Support\SearchableType;
use App\Support\SearchHit;
use App\Support\Surface;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Global search (master prompt Part D §17, Phase 10).
 *
 * ## The one rule
 *
 * > *"Query is scoped to the requester's accessible object IDs **before** ranking."*
 *
 * Every method below is that sentence. The scope and the match are **one SQL statement**, with
 * the scope in the `WHERE` clause beside the `@@` — which matters more than it looks, because
 * SQL evaluates `WHERE` before `ORDER BY` and before `LIMIT`. Ranking a row that the scope
 * excludes is therefore not merely avoided, it is unrepresentable: there is no point in the
 * execution of any query here at which an inaccessible row exists to be ranked, counted or
 * truncated.
 *
 * The shape that leaks, and the reason this is written down: rank first, take the top N, then
 * drop what the viewer may not see. Every row of THAT response is correct, and the response is
 * still a leak — the *number* of results becomes a fact about records the searcher cannot
 * open. Search a client codename you are not cleared for, watch the count move, and you have
 * learned the phrase exists. Decision M-3 made this argument for message search at one table's
 * scale; this is the same argument at eight.
 *
 * It has a second, quieter consequence that a later change is likely to undo: **the caps are
 * applied per scoped query, never to a merged pool.** `LIMIT` after the scope is a scoped
 * limit; a limit taken across types before scoping would put the leak back inside the cap.
 *
 * ## The second leak: the snippet
 *
 * A snippet is generated text. A highlighted excerpt can carry a client's name out of a
 * project's internal notes or a figure out of a finance row while every *field name* in the
 * response stays innocent, so there is no policy to ask — the thing being authorised did not
 * exist until search made it. The answer is a list rather than a judgement per call site:
 * `SearchableType::searchableColumns()` and `::snippetColumns()`, and nothing in this file cuts
 * an excerpt from a column those two do not name. See {@see excerptFrom()}.
 *
 * ## The Accountant, without the word "Accountant"
 *
 * Phase 10 says *"per shell: Accountant searches only finance records"*, and that outcome is
 * not implemented anywhere in this file. It falls out of the keys they hold:
 *
 * | Entity   | Gate this class reuses                       | Accountant |
 * | -------- | -------------------------------------------- | ---------- |
 * | Projects | `Project::visibleTo()` → `projects.view`     | no rows    |
 * | Tasks    | `Task::visibleTo()` → `tasks.view`           | no rows    |
 * | Messages | `ConversationService::search()` → `messages.use` | no rows |
 * | Meetings | `Meeting::visibleTo()` → `meetings.use`      | no rows    |
 * | People   | the Team directory's `messages.use`          | no rows    |
 * | Clients  | `ClientPolicy::view` → `clients.view_full`   | no rows    |
 * | Files    | composed from the four above                 | no rows    |
 * | Income   | `finance.view`                               | rows       |
 * | Expenses | `finance.view`                               | rows       |
 *
 * There is no `hasRole(ACCOUNTANT)` here and there must not be one — decisions 2-13 and 2-31
 * are both about why. The test that matters asserts the TYPE SET that comes back for each
 * role, so a future bookkeeper role granted `finance.view` gets exactly this behaviour with no
 * change to this file, and a future role granted `tasks.view` starts finding tasks on the same
 * terms as everybody else.
 *
 * The Admin holds `finance.view` too, so the Admin also finds finance records. That is correct
 * and is the proof the rule is a capability and not a shell.
 */
class SearchService
{
    /**
     * The shortest term worth running, and the same number message search uses.
     *
     * A single character matches most of the database, so it is answered with nothing rather
     * than with everything — and with **200**, not 422, because a half-typed term is not a
     * malformed request (decision M-4). A search-as-you-type box that flashed an error on the
     * first keystroke would be unusable, and the palette types into this on every key.
     */
    public const MINIMUM = 2;

    /**
     * The longest term that is somebody typing. Past this the request IS malformed — see
     * `SearchRequest`, which answers 422, again per M-4.
     */
    public const MAXIMUM = 100;

    /**
     * How many hits each type contributes.
     *
     * Small on purpose. The palette is "find the thing I am thinking of", and eight projects
     * is already more than a person scans; somebody who needs the ninth needs a better term.
     * Nine types × 8 is also why {@see OVERALL} exists.
     */
    public const PER_TYPE = 8;

    /**
     * The whole response's cap, applied across groups in display order.
     *
     * It truncates GROUPS that were each already scoped and already limited — it never
     * truncates a pool that rows of different access levels were merged into, because that
     * would be the rank-then-filter shape wearing a different hat.
     */
    public const OVERALL = 40;

    /** How long a snippet is before it is cut, in characters. One line in the palette. */
    public const SNIPPET_LENGTH = 120;

    /**
     * The text-search configuration, named everywhere it is used and never defaulted.
     *
     * `simple`, not `english`, and the argument is in the migration: this corpus is proper
     * nouns, domains and codes, `english` removes stop words (so a client genuinely called
     * *The Gap* becomes unfindable, silently) and stems names into each other. What stemming
     * would have bought is bought back by prefix-matching in {@see tsquery()} instead.
     *
     * The one-argument `to_tsvector(text)` reads `default_text_search_config` and is only
     * STABLE, which is why the generated columns could not have used it even if the default
     * were right. Naming the config in both places is what makes the query and the index the
     * same expression.
     */
    private const CONFIG = 'simple';

    public function __construct(private readonly ConversationService $conversations) {}

    /**
     * Everything this person can find for this term, grouped by type.
     *
     * `$types` narrows the search to those types (the palette's per-section scope, brief 010);
     * null searches every type. It only decides which of the per-type methods below RUN — each
     * one that runs is scoped exactly as it always was, the caps are unchanged, and the groups
     * still come back in `inDisplayOrder()`, never in the order they were asked for.
     *
     * @param  list<SearchableType>|null  $types
     * @return array{term: string, total: int, truncated: bool, groups: list<array{type: string, label: string, hits: list<SearchHit>}>}
     */
    public function search(User $user, string $term, ?array $types = null): array
    {
        $term = trim($term);
        $empty = ['term' => $term, 'total' => 0, 'truncated' => false, 'groups' => []];

        // A deactivated user finds nothing. The `active` middleware already turned them away
        // at the route, and every `visibleTo()` below asks `isActive()` again for itself; this
        // is the third statement of it and it is here so that a caller reaching the service
        // directly — a console command, a future job, a test — gets the same answer as the
        // endpoint. Three agreeing statements of a privacy rule is the right number.
        if (! $user->isActive()) {
            return $empty;
        }

        if (mb_strlen($term) < self::MINIMUM) {
            return $empty;
        }

        $tsquery = self::tsquery($term);

        // Nothing the user typed survived into a lexeme — a bare `@`, a row of punctuation, a
        // lone `"`. That is a term that matches nothing, not a term that is invalid: 200 with
        // an empty body, for M-4's reason.
        if ($tsquery === null) {
            return $empty;
        }

        $groups = [];
        $total = 0;
        $truncated = false;

        foreach (SearchableType::inDisplayOrder() as $type) {
            if ($types !== null && ! in_array($type, $types, true)) {
                continue;
            }

            if ($total >= self::OVERALL) {
                $truncated = true;

                break;
            }

            $hits = $this->hitsFor($user, $type, $term, $tsquery);

            if ($hits === []) {
                continue;
            }

            // The overall cap bites on a group that was already scoped and already limited.
            if ($total + count($hits) > self::OVERALL) {
                $hits = array_slice($hits, 0, self::OVERALL - $total);
                $truncated = true;
            }

            $total += count($hits);

            $groups[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'hits' => $hits,
            ];
        }

        return [
            'term' => $term,
            'total' => $total,
            'truncated' => $truncated,
            'groups' => $groups,
        ];
    }

    /**
     * @return list<SearchHit>
     */
    private function hitsFor(User $user, SearchableType $type, string $term, string $tsquery): array
    {
        return match ($type) {
            SearchableType::Project => $this->projects($user, $term, $tsquery),
            SearchableType::Task => $this->tasks($user, $term, $tsquery),
            SearchableType::Meeting => $this->meetings($user, $term, $tsquery),
            SearchableType::Message => $this->messages($user, $term),
            SearchableType::Client => $this->clients($user, $term, $tsquery),
            SearchableType::Employee => $this->people($user, $tsquery),
            SearchableType::File => $this->files($user, $term, $tsquery),
            SearchableType::Income => $this->income($user, $term, $tsquery),
            SearchableType::Expense => $this->expenses($user, $term, $tsquery),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | One entity, one scope, one statement
    |--------------------------------------------------------------------------
    |
    | Each method below reuses the access rule that already exists for its entity. None of
    | them writes a second one, and that is the point: the day `Task::visibleTo()` changes,
    | search changes with it, and there is no copy of the rule here to be forgotten.
    |
    */

    /**
     * Projects, scoped by `Project::visibleTo()` — which is `ProjectPolicy::view` as a query
     * and already answers "no `projects.view`, no rows".
     *
     * The vector holds `name`, `domain` and `employee_notes`. It does NOT hold the client's
     * name, which is what makes Phase 10's own sentence true: *"search for 'Buffalo' as Tapu
     * returns the project and never the client record or price"*. Tapu finds
     * *Buffalo Modular — SEO* because the PROJECT is called that. Were the client name
     * denormalised into this vector, Tapu would also match projects that are merely billed to
     * Buffalo, and the hit itself would be the disclosure.
     *
     * @return list<SearchHit>
     */
    private function projects(User $user, string $term, string $tsquery): array
    {
        $base = $this->surfaceBase($user);

        if ($base === null) {
            return [];
        }

        $query = Project::query()
            ->visibleTo($user)
            ->select(['projects.id', 'projects.name', 'projects.domain', 'projects.employee_notes']);

        return $this->match($query, 'projects', $tsquery)
            ->map(fn (Project $project): SearchHit => new SearchHit(
                type: SearchableType::Project,
                id: (int) $project->getKey(),
                label: (string) $project->name,
                snippet: $this->excerptFrom(SearchableType::Project, $project->getAttributes(), $term),
                href: route($base.'.projects.show', $project->getKey(), absolute: false),
                rank: (float) $project->getAttribute('search_rank'),
            ))
            ->all();
    }

    /**
     * Tasks, scoped by `Task::visibleTo()`.
     *
     * The difference from a project, and the reason the employee negative test is a separate
     * one: an employee sees the tasks ASSIGNED to them, not every task on a project they are a
     * member of. That is the scope's rule, restated here only as a comment.
     *
     * @return list<SearchHit>
     */
    private function tasks(User $user, string $term, string $tsquery): array
    {
        $base = $this->surfaceBase($user);

        if ($base === null) {
            return [];
        }

        $query = Task::query()
            ->visibleTo($user)
            ->select(['tasks.id', 'tasks.title', 'tasks.description', 'tasks.work_summary']);

        return $this->match($query, 'tasks', $tsquery)
            ->map(fn (Task $task): SearchHit => new SearchHit(
                type: SearchableType::Task,
                id: (int) $task->getKey(),
                label: (string) $task->title,
                snippet: $this->excerptFrom(SearchableType::Task, $task->getAttributes(), $term),
                href: route($base.'.tasks.show', $task->getKey(), absolute: false),
                rank: (float) $task->getAttribute('search_rank'),
            ))
            ->all();
    }

    /**
     * Meetings, scoped by `Meeting::visibleTo()` — organiser or participant, every meeting for
     * an Admin, and nothing at all without `meetings.use`.
     *
     * @return list<SearchHit>
     */
    private function meetings(User $user, string $term, string $tsquery): array
    {
        $query = Meeting::query()
            ->visibleTo($user)
            ->select(['meetings.id', 'meetings.title', 'meetings.agenda']);

        return $this->match($query, 'meetings', $tsquery)
            ->map(fn (Meeting $meeting): SearchHit => new SearchHit(
                type: SearchableType::Meeting,
                id: (int) $meeting->getKey(),
                label: (string) $meeting->title,
                snippet: $this->excerptFrom(SearchableType::Meeting, $meeting->getAttributes(), $term),
                // Shared route: whose calendar a meeting is on belongs to the person, not to
                // the shell, so there is one address for it and every surface uses it.
                href: route('meetings.show', $meeting->getKey(), absolute: false),
                rank: (float) $meeting->getAttribute('search_rank'),
            ))
            ->all();
    }

    /**
     * Messages — by **calling Phase 6's search**, not by writing a second one.
     *
     * `ConversationService::search()` scopes to `inboxFor()` before its `ILIKE` runs (decision
     * M-3), which is the same order this whole class is built on, and its inbox is policy-
     * checked row by row. It matches with `ILIKE` rather than a tsvector, and `messages` is
     * therefore the one table in Part D §17's list with no vector — the migration says why.
     *
     * A second matcher here would have been a second answer to "may this person read this
     * room", which is the thing M-3 exists to prevent.
     *
     * @return list<SearchHit>
     */
    private function messages(User $user, string $term): array
    {
        return $this->conversations->search($user, $term, self::PER_TYPE)
            ->take(self::PER_TYPE)
            ->map(fn (Message $message): SearchHit => new SearchHit(
                type: SearchableType::Message,
                id: (int) $message->getKey(),
                // The ROOM names the hit, because "a message" is not a thing anybody is
                // looking for — the thread it is in is. `labelFor()` is per reader (a DM is
                // named after the other person), which is why it is a method and not a column.
                label: $message->conversation?->labelFor($user) ?? 'Conversation',
                snippet: $this->excerpt((string) $message->body, $term),
                href: route('messages.show', (int) $message->conversation_id, absolute: false),
            ))
            ->values()
            ->all();
    }

    /**
     * Clients — `ClientPolicy::view`, written as a query.
     *
     * It is written out here rather than added as a `Client::scopeVisibleTo()` because this
     * slice does not own `app/Models/Client.php`. The two must agree, so
     * `SearchScopingTest::'the client search scope answers exactly what ClientPolicy::view
     * does'` asserts them equal for every seeded user against every seeded client, row by row
     * — which is the check that would actually catch a drift, and is the same belt
     * `Meeting::visibleTo()` wears against `MeetingPolicy::view`.
     *
     * The policy in three lines, in the same order:
     *
     *   - no `clients.view_full` → nothing;
     *   - a MANAGER → only clients they have an assigned project for;
     *   - anybody else holding the key → every client.
     *
     * **The Accountant is not mentioned and must not be.** They hold no `clients.view_full`, so
     * the first line answers them, and decision 8-7 — *no client access at all* — is honoured
     * by the key rather than by their name. A finance-only project window is not a door to a
     * client, and neither is this.
     *
     * The surface guard is the second half and is about links, not privacy: clients exist only
     * on the Admin surface, so a hit for somebody whose shell has no `clients/{id}` page would
     * be a result that cannot be opened. A record with no page on your surface is not a search
     * result.
     *
     * @return list<SearchHit>
     */
    private function clients(User $user, string $term, string $tsquery): array
    {
        if ($user->surface() !== Surface::Admin) {
            return [];
        }

        $query = $this->clientScope($user);

        if ($query === null) {
            return [];
        }

        return $this->match($query->select(['clients.id', 'clients.name']), 'clients', $tsquery)
            ->map(fn (Client $client): SearchHit => new SearchHit(
                type: SearchableType::Client,
                id: (int) $client->getKey(),
                label: (string) $client->name,
                // A client result is a NAME and nothing else: no contact, no note, no count of
                // projects. `SearchableType::snippetColumns()` returns [] for this type and
                // this is what that decision looks like at the call site.
                snippet: null,
                href: route('admin.clients.show', $client->getKey(), absolute: false),
                rank: (float) $client->getAttribute('search_rank'),
            ))
            ->all();
    }

    /**
     * `ClientPolicy::view` as a builder, or null when this person may see no client at all.
     *
     * **Public on purpose**, and it is the only method here that is. `Project::visibleTo()`
     * and `Task::visibleTo()` are public scopes that a test can hold up against their
     * policies; this rule has no model to live on in this slice, so it is exposed here
     * instead — otherwise the one access rule in the file that is not already covered by
     * somebody else's test would be the one nothing could check.
     *
     * @return Builder<Client>|null
     */
    public function clientScope(User $user): ?Builder
    {
        if (! $user->isActive() || ! $user->hasPermission(Permission::ClientsViewFull)) {
            return null;
        }

        $query = Client::query();

        if (! $user->hasRole(RoleName::MANAGER)) {
            return $query;
        }

        $employee = $user->employee;

        if ($employee === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('projects', fn (Builder $projects) => $projects->forEmployee($employee));
    }

    /**
     * People, from the Team directory's own rule.
     *
     * The directory (`TeamController`) is deliberately not scoped per row — *"a name, a role
     * and a status word, none of which is anybody's private business"* — and is gated as a
     * whole by `messages.use`. Search says the same thing the same way: hold the key, find
     * anyone who works here; hold it not, find nobody. The Accountant holds it not.
     *
     * **Part H applies here and nowhere harder.** The vector is `users.name` and only
     * `users.name`. Not the email, not the phone, and nothing that could rank a person: no
     * task count, no hours, no salary, no availability. Searching for a person finds the
     * person; it does not describe them.
     *
     * Inactive people are absent for the reason the directory omits them — they do not work
     * here — and the join asks both halves of that, as `TeamController` does.
     *
     * @return list<SearchHit>
     */
    private function people(User $user, string $tsquery): array
    {
        if (! $user->hasPermission(Permission::MessagesUse)) {
            return [];
        }

        $query = Employee::query()
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->where('employees.status', UserStatus::Active->value)
            ->where('users.status', UserStatus::Active->value)
            ->select(['employees.id', 'users.name']);

        return $this->match($query, 'users', $tsquery, 'employees.id')
            ->map(fn (Employee $employee): SearchHit => new SearchHit(
                type: SearchableType::Employee,
                id: (int) $employee->getKey(),
                label: (string) $employee->getAttribute('name'),
                snippet: null,
                // There is no `/team/{employee}`, by design — routes/shared.php says so and
                // says why. The directory is the person's page.
                href: route('team.index', absolute: false),
                rank: (float) $employee->getAttribute('search_rank'),
            ))
            ->all();
    }

    /**
     * Files, scoped by composing the scopes of the things files hang off.
     *
     * `FilePolicy::view` is *"`view` on the owning record"*, and this is that sentence as SQL:
     * a file is findable when its task is visible, or its project is, or its client is. Three
     * subqueries over the very scopes the other methods here use — not a fourth access rule,
     * and not a `whereIn` over a materialised id list, so the whole thing stays one statement
     * the planner can reason about.
     *
     * **Message attachments are excluded.** A file posted into a chat is owned by its message,
     * and its reader is decided by `ConversationPolicy` asking the linked task or project — a
     * fourth branch, over a table (`conversations`) whose membership is computed rather than
     * stored, which cannot be expressed as a subquery without restating that computation. It
     * is left out rather than approximated: an approximation of a membership rule is how a
     * room somebody cannot enter starts showing its filenames. They remain reachable through
     * their message, in the thread, where they were posted.
     *
     * @return list<SearchHit>
     */
    private function files(User $user, string $term, string $tsquery): array
    {
        $base = $this->surfaceBase($user);

        if ($base === null) {
            return [];
        }

        $clients = $this->clientScope($user);

        $query = File::query()
            ->whereNull('files.superseded_at')
            ->where(function (Builder $owned) use ($user, $clients): void {
                $owned
                    ->whereIn('files.task_id', Task::query()->visibleTo($user)->select('tasks.id'))
                    ->orWhereIn('files.project_id', Project::query()->visibleTo($user)->select('projects.id'));

                if ($clients !== null) {
                    $owned->orWhereIn('files.client_id', $clients->clone()->select('clients.id'));
                }
            })
            ->select([
                'files.id',
                'files.name',
                'files.task_id',
                'files.project_id',
                'files.client_id',
            ]);

        return $this->match($query, 'files', $tsquery)
            ->map(fn (File $file): SearchHit => new SearchHit(
                type: SearchableType::File,
                id: (int) $file->getKey(),
                label: (string) $file->name,
                snippet: null,
                href: $this->fileHref($file, $base),
                rank: (float) $file->getAttribute('search_rank'),
            ))
            ->all();
    }

    /**
     * Where a file result goes: to the record it is attached to, not to the bytes.
     *
     * `GET /files/{file}` is a `temporarySignedRoute` that re-runs `FilePolicy` and then
     * DOWNLOADS. A search result whose Enter key starts a download is not a search result, and
     * a signed URL minted into a palette payload would be a capability sitting in a JSON
     * response. The owning page is the answer, and it is the page the person wanted anyway.
     */
    private function fileHref(File $file, string $base): string
    {
        if ($file->task_id !== null) {
            return route($base.'.tasks.show', $file->task_id, absolute: false);
        }

        if ($file->project_id !== null) {
            return route($base.'.projects.show', $file->project_id, absolute: false);
        }

        return route('admin.clients.show', $file->client_id, absolute: false);
    }

    /**
     * Money in, scoped by `finance.view` — the key the whole finance route group is gated on.
     *
     * The Admin and the Accountant both hold it and both find these rows, which is the point:
     * the books are a capability, not a shell. Employees hold neither finance key and find
     * nothing here, with nothing in this method mentioning them either.
     *
     * @return list<SearchHit>
     */
    private function income(User $user, string $term, string $tsquery): array
    {
        if (! $user->hasPermission(Permission::FinanceView)) {
            return [];
        }

        $query = Income::query()
            ->with('category')
            ->select(['income.id', 'income.notes', 'income.amount', 'income.date', 'income.category_id']);

        return $this->match($query, 'income', $tsquery)
            ->map(fn (Income $row): SearchHit => new SearchHit(
                type: SearchableType::Income,
                id: (int) $row->getKey(),
                label: $this->moneyLabel($row->category?->name, $row->amount, $row->date?->toDateString()),
                snippet: $this->excerptFrom(SearchableType::Income, $row->getAttributes(), $term),
                href: $this->ledgerHref('finance.income.index', $row->date?->format('Y-m')),
                rank: (float) $row->getAttribute('search_rank'),
            ))
            ->all();
    }

    /**
     * Money out. The same gate and the same shape as {@see Income()}.
     *
     * @return list<SearchHit>
     */
    private function expenses(User $user, string $term, string $tsquery): array
    {
        if (! $user->hasPermission(Permission::FinanceView)) {
            return [];
        }

        $query = Expense::query()
            ->with('category')
            ->select(['expenses.id', 'expenses.notes', 'expenses.amount', 'expenses.date', 'expenses.category_id']);

        return $this->match($query, 'expenses', $tsquery)
            ->map(fn (Expense $row): SearchHit => new SearchHit(
                type: SearchableType::Expense,
                id: (int) $row->getKey(),
                label: $this->moneyLabel($row->category?->name, $row->amount, $row->date?->toDateString()),
                snippet: $this->excerptFrom(SearchableType::Expense, $row->getAttributes(), $term),
                href: $this->ledgerHref('finance.expenses.index', $row->date?->format('Y-m')),
                rank: (float) $row->getAttribute('search_rank'),
            ))
            ->all();
    }

    /**
     * A money row names itself by what it was filed under, for how much, and when.
     *
     * The amount is in the LABEL and not hidden, because the only people who reach this method
     * hold `finance.view` and the amount is the first thing they are looking for. It never
     * reaches anybody else, because the two callers return `[]` before composing it.
     */
    private function moneyLabel(?string $category, mixed $amount, ?string $date): string
    {
        return implode(' · ', array_filter([
            $category,
            $amount === null ? null : number_format((float) $amount, 2),
            $date,
        ]));
    }

    /**
     * The ledger, opened on the month the row is in.
     *
     * Not `…/{id}/edit`: that address is an ACT on a record, guarded by `finance.manage`
     * through the policy, and a search result that lands a read-only viewer on a form they may
     * not submit is a link that lies. The month is in the URL because that is how every
     * finance screen in this application is addressed (`?month=YYYY-MM`), so the row is on the
     * page that opens.
     */
    private function ledgerHref(string $route, ?string $month): string
    {
        return route($route, $month === null ? [] : ['month' => $month], absolute: false);
    }

    /*
    |--------------------------------------------------------------------------
    | The match, the query, the excerpt
    |--------------------------------------------------------------------------
    */

    /**
     * Add the tsvector predicate and the ranking to an ALREADY-SCOPED builder, and take the
     * first {@see PER_TYPE}.
     *
     * The argument order of this method is the argument of the whole slice: it receives a
     * query that is already narrowed to what the viewer may see and adds matching to it. It
     * can only ever be called that way round, because it has no idea who is asking.
     *
     * Both the predicate and the `ts_rank` bind the same query string twice rather than
     * referencing a CTE, which is deliberate: PostgreSQL evaluates `to_tsquery` once per
     * distinct expression per row at worst, and a CTE here would be an optimisation fence on a
     * query that returns eight rows.
     *
     * `$table` is never user input — it comes from `SearchableType::vectorTable()`.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $scoped
     * @return Collection<int, TModel>
     */
    private function match(Builder $scoped, string $table, string $tsquery, ?string $tieBreaker = null): Collection
    {
        $vector = $table.'.search_vector';
        $config = self::CONFIG;

        return $scoped
            ->whereRaw("{$vector} @@ to_tsquery('{$config}', ?)", [$tsquery])
            // `ts_rank` rides along as a column so the hit can carry its own score, and the
            // ranking test can read it rather than inferring it from the order.
            ->selectRaw("ts_rank({$vector}, to_tsquery('{$config}', ?)) as search_rank", [$tsquery])
            ->orderByDesc('search_rank')
            // Stable order. Two rows with the same rank must not swap places between two
            // identical requests, because the palette re-fetches on every keystroke and a list
            // that reorders under a moving selection is unusable — and because
            // `SearchRankingTest` asserts the same query twice.
            ->orderByDesc($tieBreaker ?? $table.'.id')
            ->limit(self::PER_TYPE)
            ->get();
    }

    /**
     * Turn what somebody typed into a `tsquery`, or null when nothing survives.
     *
     * Built in PHP and passed as a bound parameter to `to_tsquery`, which is safe only because
     * of the whitelist below — `to_tsquery` THROWS on a malformed query, and `&`, `|`, `!`,
     * `(`, `)`, `:` and `<` are all operators in its grammar. Every character that is not a
     * letter, a digit, `.`, `_`, `-` or `@` is replaced with a SPACE rather than deleted, so
     * `O'Brien` becomes two tokens that match the two lexemes the parser produces from the
     * name, instead of one token (`OBrien`) that matches neither.
     *
     * `websearch_to_tsquery` was the alternative and is the better function for a search page:
     * it never throws and it understands `or` and `-not`. It is not used here because it
     * cannot prefix-match, and a palette that finds nothing until you finish typing the word
     * is a palette nobody opens twice. What the user gets instead:
     *
     *   - `buffalo modular` → `buffalo:* & modular:*`. Every word must appear; the last one
     *     the user is still typing matches as a prefix, so `buff mod` already finds
     *     *Buffalo Modular — SEO*.
     *   - `"home model"` → `home <-> model`, a PHRASE: adjacent, in that order, and NOT
     *     prefix-matched — somebody who reached for quotes asked for exactness.
     *   - `seo "home model"` → `seo:* & home <-> model`. The two forms compose.
     *   - a bare `@`, `%%%`, or a lone `"` → **null**, and the caller answers 200 with nothing.
     *     A term with no lexemes in it is a term that matches nothing; it is not a bad request,
     *     and M-4 is why that distinction is worth a branch.
     */
    private static function tsquery(string $term): ?string
    {
        $groups = [];

        // Quoted phrases first, so their contents are not also read as loose words.
        $rest = preg_replace_callback(
            '/"([^"]*)"/u',
            function (array $match) use (&$groups): string {
                $tokens = self::tokenise($match[1]);

                if ($tokens !== []) {
                    // `<->` is FOLLOWED BY: exactly adjacent, in order. A one-word phrase is
                    // just that word, exact rather than prefixed.
                    $groups[] = implode(' <-> ', $tokens);
                }

                return ' ';
            },
            $term,
        ) ?? $term;

        foreach (self::tokenise($rest) as $token) {
            $groups[] = $token.':*';
        }

        return $groups === [] ? null : implode(' & ', $groups);
    }

    /**
     * The whitelist, and the only place a user's characters become query syntax.
     *
     * A token is kept only if it still contains a letter or a digit after trimming, which is
     * what makes `@` alone, `-` alone and `...` alone disappear rather than reach `to_tsquery`
     * as a lexeme-free fragment.
     *
     * @return list<string>
     */
    private static function tokenise(string $text): array
    {
        $cleaned = preg_replace('/[^\p{L}\p{N}._@-]+/u', ' ', $text) ?? '';

        $tokens = [];

        foreach (preg_split('/\s+/u', $cleaned, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            // Punctuation may legitimately sit INSIDE a token (`buffalomodular.com`,
            // `karen@example.test`, `EMP-003`) and never usefully at its edges.
            $token = trim($token, '._@-');

            if ($token !== '' && preg_match('/[\p{L}\p{N}]/u', $token) === 1) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * One line cut from the first SNIPPET column that has something to say.
     *
     * The column list comes from `SearchableType` and this method cannot be given another one:
     * it takes the type, not a column name. That is the whole mechanism by which "snippets
     * never contain restricted fields" is a property of the code rather than a thing every
     * call site has to remember — there is no call site that could pass
     * `projects.internal_notes` in, because no call site names a column at all.
     *
     * @param  array<string, mixed>  $attributes  the row's raw attributes
     */
    private function excerptFrom(SearchableType $type, array $attributes, string $term): ?string
    {
        $fallback = null;

        foreach ($type->snippetColumns() as $column) {
            $value = $attributes[$column] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            // Prefer the column that actually contains what was typed; a snippet that shows
            // the reader why the row matched is the only kind worth the line it takes.
            foreach (self::tokenise($term) as $token) {
                if (mb_stripos($value, $token) !== false) {
                    return $this->excerpt($value, $term);
                }
            }

            $fallback ??= $value;
        }

        return $fallback === null ? null : $this->excerpt($fallback, $term);
    }

    /**
     * A window of text centred on the first token of the term, ellipsised on whichever side
     * was cut. The same shape `MessageController::excerpt()` cuts, and for the same reason: an
     * excerpt taken from the start of a long note usually does not contain the match.
     */
    private function excerpt(string $text, string $term, int $length = self::SNIPPET_LENGTH): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $total = mb_strlen($text);

        if ($total <= $length) {
            return $text;
        }

        $token = self::tokenise($term)[0] ?? '';
        $position = $token === '' ? false : mb_stripos($text, $token);

        $lead = max(0, (int) (($length - mb_strlen($token)) / 2));
        $start = max(0, ($position === false ? 0 : $position) - $lead);
        $start = min($start, $total - $length);

        $cut = mb_substr($text, $start, $length);

        return ($start > 0 ? '…' : '').trim($cut).($start + $length < $total ? '…' : '');
    }

    /**
     * Which shell's addresses this person's links must use.
     *
     * The Meetings slice hit this exactly: an employee handed `/admin/projects/12` meets a 403
     * dressed up as a link, so the base is resolved on the SERVER for the viewer rather than
     * guessed in the palette — see `MessageController::surfaceBase()`.
     *
     * It returns null for the Accountant, whose shell has no projects, tasks or files page at
     * all. That null is not a privacy rule — `Project::visibleTo()` has already given them no
     * rows before this is reached — it is the second, independent reason those three methods
     * can never emit a link into a surface the reader cannot open.
     */
    private function surfaceBase(User $user): ?string
    {
        return match ($user->surface()) {
            Surface::Admin => 'admin',
            Surface::Employee => 'employee',
            Surface::Accountant, null => null,
        };
    }
}

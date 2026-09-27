<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\UserStatus;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Audit Log (master prompt Part E, Phase 12: *"Admin → Audit Log viewer (filters,
 * old/new diff, read-only)"*).
 *
 * Eleven phases wrote to `audit_logs` — every salary change, every project price, every role
 * change, every payroll lock reversal, every refused read of somebody else's payslip — and
 * nothing in the application had ever read one back. This is the one screen that does.
 *
 * ## Read-only, and there is no write route to forget
 *
 * This controller has **one action** and the route file has **one line**: `GET
 * /admin/audit-log`. There is no store, no update, no destroy, not a soft one, and no
 * "correct this entry" — and that is enforced three layers below anything written here:
 *
 *   1. `audit_logs`' Phase 0 migration runs `REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM
 *      hq_app` immediately after `CREATE TABLE`, and `hq_app` is not the table's owner, so it
 *      cannot grant them back to itself (Part B §3 rule 3).
 *   2. `tests/Feature/Database/AuditLogAppendOnlyTest.php` proves it: as `hq_app`, inside a
 *      savepoint, raw `UPDATE`, `DELETE` and `TRUNCATE` each raise `42501
 *      insufficient_privilege`, and the row is still there afterwards.
 *   3. `AuditLog` refuses `updating` and `deleting` at the model, so an Eloquent path throws
 *      before it reaches the database at all.
 *
 * So acceptance criterion 11 — *"the audit log … is not editable through the application by any
 * role, including ADMIN"* — is not a promise this controller keeps by being careful. There is
 * nothing here to be careful with.
 *
 * ## 403 for the route, and no 404 anywhere
 *
 * `can:audit.view` on the route is Part C §1's *View audit log* row, which is ADMIN alone. Note
 * what that means for the ACCOUNTANT: they hold `payroll.view_others` and may read every payslip
 * in the agency, and they are still **403** here, because the history of who changed what is a
 * different question from what it now says. `surface:admin` refuses them first; the permission is
 * what would still refuse them the day a second shell got this screen.
 *
 * There is deliberately **no per-row scope and no 404 by id**. Every row in this table is in
 * scope for anybody holding `audit.view` — see `AuditLogPolicy`, which says why at length. A
 * `visibleTo()` here would be a scope that filtered nothing, and the reader of a compliance log
 * has to be able to see the rows about themselves.
 *
 * ## `old_value` / `new_value` are not stripped
 *
 * They can contain a salary or a project price, and they must: Part C §4 requires those two
 * events to be recorded *with old and new values*. `AuditLogResource`'s docblock carries the
 * argument; the short version is that the field-absence rule protects the endpoints serving the
 * **record**, and this one serves the history to the one role allowed to read history. What keeps
 * it contained is that the resource has exactly one caller and the route has exactly one gate.
 *
 * ## Reading the log is not itself audited
 *
 * Part C §4's list of events that must be recorded does not include reading the log, and a table
 * that grew a row every time somebody looked at it would, within a week, mostly be a record of
 * being read. Part C §3's *"every access attempt to another employee's salary or to project price
 * by an unauthorized role is logged"* is about a refused read of a record; a permitted read of the
 * log by the only role that may is not that.
 */
class AuditLogController extends Controller
{
    /** How many rows one page of the log carries. */
    private const PER_PAGE = 25;

    /**
     * The whole log, newest first, narrowed by up to five filters.
     */
    public function index(AuditLogIndexRequest $request): Response
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = $request->filters();

        $entries = $this->query($filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Read before `AuditLogResource::collection()` is built: a resource collection over a
        // paginator replaces the paginator's own collection with the resolved resources, so
        // asking the paginator for its models afterwards hands back something else entirely.
        $idsOnPage = $entries->getCollection()
            ->map(fn (AuditLog $log): int => (int) $log->getKey())
            ->all();

        $deepLinked = $this->deepLinked($request, $idsOnPage);

        return Inertia::render('Admin/AuditLog/Index', [
            'entries' => AuditLogResource::collection($entries),
            'filters' => $filters,
            'options' => $this->options(),

            // Named once on the page, because every timestamp on it is in this zone and the date
            // filter's boundaries are drawn in it — see `between()`.
            'timezone' => (string) config('app.timezone'),

            // The row a pasted `?detail=` link names, when it is not on the page the link also
            // asked for. `DetailDrawer` writes that parameter with `replaceState` and cannot
            // fetch what it names, so without this a shared link to an entry on page 1 of an
            // unfiltered log opens an empty drawer for a colleague whose filters differ. There is
            // no scope to apply and so no 404 to give: an id that is not a row is simply null.
            'entry' => $deepLinked,
        ]);
    }

    /**
     * The log, filtered and ordered.
     *
     * ## Ordered by `id`, which on this table *is* chronological
     *
     * `audit_logs` is append-only and its `created_at` is set by the database
     * (`timestampTz('created_at')->useCurrent()`), so id order and insertion order are the same
     * order — and `id` is the primary key while **`created_at` is not indexed at all**. Ordering
     * by `created_at` would mean sorting the whole filtered set on every page of a table that is
     * expected to be large; ordering by `id` walks the primary-key index backwards and is also
     * stable, which `created_at` alone is not when two rows share a timestamp to the microsecond.
     *
     * (This is not the `updated_at` trap: the column does not exist, `AuditLog::UPDATED_AT` is
     * null, and nothing here asks for it. The date **filter** still compares `created_at`,
     * because that is the fact a reader means by a date.)
     *
     * ## What is indexed, and what is not
     *
     * Checked against the Phase 0 migration rather than assumed: the only index on this table
     * besides the primary key is **`event`**. `actor_id` carries a foreign key, which PostgreSQL
     * does not index; `target_type`, `target_id` and `created_at` have nothing. So the event
     * filter is cheap and the other three are a scan of whatever the event filter left. That is
     * reported rather than fixed here — a migration is not this slice's territory — and it is why
     * every one of these filters is `AND`ed onto the same query instead of being resolved
     * separately.
     *
     * @param  array{actor: string|null, event: string|null, target_type: string|null, date_from: string|null, date_to: string|null}  $filters
     * @return Builder<AuditLog>
     */
    private function query(array $filters): Builder
    {
        return AuditLog::query()
            ->with('actor')
            ->when(
                $filters['actor'] !== null,
                fn (Builder $query) => $filters['actor'] === AuditLogIndexRequest::SYSTEM_ACTOR
                    // Rows nobody signed in for: a console command, a queue worker, the
                    // scheduler. "Which of these did nobody do" cannot be asked by leaving the
                    // filter off, because off means everybody.
                    ? $query->whereNull('actor_id')
                    : $query->where('actor_id', (int) $filters['actor']),
            )
            // Compared as a string, never resolved through the enum: a row written by an older
            // build can carry an event this build has no case for, and it is still a row a
            // reader may want alone on the screen.
            ->when($filters['event'], fn (Builder $query, string $event) => $query->where('event', $event))
            ->when($filters['target_type'], fn (Builder $query, string $type) => $query->where('target_type', $type))
            ->when($filters['date_from'], fn (Builder $query, string $from) => $query->where(
                'created_at',
                '>=',
                $this->boundary($from, start: true),
            ))
            ->when($filters['date_to'], fn (Builder $query, string $to) => $query->where(
                'created_at',
                '<=',
                $this->boundary($to, start: false),
            ))
            ->orderByDesc('id');
    }

    /**
     * One end of the date range, as an instant.
     *
     * The reader typed a calendar day, and which instants that day covers depends on whose day
     * it is. It is the **agency's** — `config('app.timezone')`, the same zone every timestamp on
     * the page is rendered in — so that a row at 00:30 in Dhaka is in Dhaka's today and not in
     * UTC's yesterday. `whereDate()` would have compared in the database session's zone (UTC)
     * and quietly disagreed with the column beside it.
     */
    private function boundary(string $date, bool $start): Carbon
    {
        $day = Carbon::createFromFormat('Y-m-d', $date, (string) config('app.timezone'));

        return $start ? $day->startOfDay() : $day->endOfDay();
    }

    /**
     * What the filter chips may offer.
     *
     * ## Events and target types come from the table, not from the enum
     *
     * The list is what has actually been recorded, which is better than `AuditEvent::cases()` in
     * both directions: it does not offer *Payroll lock reversed* on a log where it has never
     * happened, and it **does** offer an event string this build no longer has a case for, which
     * is exactly the row a reader of an old log is hunting. Each option still carries the enum's
     * label and family where there is one, so the picker reads in English and groups by question
     * rather than alphabetically.
     *
     * One grouped query for both lists rather than two `SELECT DISTINCT`s: `target_type` has no
     * index, so that half is a scan whatever shape it is asked in, and one scan returning both is
     * cheaper than an index-only scan of `event` plus the same scan anyway.
     *
     * ## Actors come from `users`
     *
     * Not from the log — that would be a second scan of the large table for a list of at most a
     * few dozen names. Every account may act, so every account is offered, and an actor with no
     * rows yet answers with an empty list rather than being unaskable. The `System` option is
     * first because it is the one nobody would think to look for.
     *
     * @return array{actors: list<array{value: string, label: string}>, events: list<array{value: string, label: string, group: string}>, target_types: list<array{value: string, label: string}>}
     */
    private function options(): array
    {
        $recorded = AuditLog::query()
            ->selectRaw('event, target_type')
            ->groupBy('event', 'target_type')
            ->get();

        $events = $recorded
            ->pluck('event')
            ->filter(fn (mixed $event): bool => is_string($event) && $event !== '')
            ->unique()
            ->map(fn (string $event): array => [
                'value' => $event,
                'label' => AuditEvent::labelFor($event),
                'group' => AuditEvent::groupFor($event),
            ])
            // Family first, in the order `AuditEvent::groups()` names them, then label. A flat
            // alphabetical list of thirty-five events is a memory test; grouped, "the money ones"
            // is one glance.
            ->sortBy([
                fn (array $option): int => (int) array_search($option['group'], AuditEvent::groups(), strict: true),
                fn (array $option): string => $option['label'],
            ])
            ->values()
            ->all();

        $targetTypes = $recorded
            ->pluck('target_type')
            ->filter(fn (mixed $type): bool => is_string($type) && $type !== '')
            ->unique()
            ->map(fn (string $type): array => [
                'value' => $type,
                // The same derivation `AuditLogResource` uses for a row's target, so the chip and
                // the column cannot print two different names for one class.
                'label' => AuditLogResource::targetLabel($type),
            ])
            ->sortBy('label')
            ->values()
            ->all();

        $actors = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'status'])
            ->map(fn (User $user): array => [
                'value' => (string) $user->getKey(),
                // Somebody who has left keeps their rows for ever (Part B §3 rule 11), so they
                // stay in this list and are marked with the WORD rather than by being dropped —
                // a filter that hid them would make half the log unaskable.
                'label' => $user->status === UserStatus::Inactive
                    ? $user->name.' (inactive)'
                    : (string) $user->name,
            ])
            ->all();

        return [
            'actors' => [
                ['value' => AuditLogIndexRequest::SYSTEM_ACTOR, 'label' => 'System (no signed-in actor)'],
                ...$actors,
            ],
            'events' => $events,
            'target_types' => $targetTypes,
        ];
    }

    /**
     * The entry a `?detail=` link names, unless the page already carries it.
     *
     * @param  list<int>  $idsOnPage
     * @return array<string, mixed>|null
     */
    private function deepLinked(AuditLogIndexRequest $request, array $idsOnPage): ?array
    {
        $id = (int) $request->query('detail', 0);

        if ($id <= 0 || in_array($id, $idsOnPage, true)) {
            return null;
        }

        $entry = AuditLog::query()->with('actor')->find($id);

        return $entry === null ? null : AuditLogResource::make($entry)->resolve($request);
    }
}

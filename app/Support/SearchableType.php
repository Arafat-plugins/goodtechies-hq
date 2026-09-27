<?php

namespace App\Support;

/**
 * The seven-and-a-bit kinds of thing global search can return, and — for each — the three
 * lists that decide what a searcher is allowed to learn about it (master prompt Part D §17,
 * Phase 10).
 *
 * ## Why this is an enum and not a judgement at each call site
 *
 * Phase 10's security line has two halves. The first, *"query is scoped to the requester's
 * accessible object IDs before ranking"*, is answered by `SearchService` reusing each model's
 * existing `visibleTo()` scope — one access rule per entity, the one that already exists.
 *
 * The second, *"snippets never contain restricted fields"*, has no such single home, because a
 * snippet is **generated text**: a highlighted excerpt is a new sentence assembled by the
 * server, and it can carry a client's name out of a project's internal notes or an amount out
 * of a finance row without any field of the response being named after it. There is no policy
 * to ask, because the object being authorised did not exist until the search made it.
 *
 * So the answer is a list, written once, here:
 *
 *   - {@see searchableColumns()} — what is in the row's `tsvector`, and therefore what a
 *     search can MATCH on. A column that is not on this list cannot cause a hit.
 *   - {@see snippetColumns()} — what an excerpt may be cut from, in the order tried. Always a
 *     subset of the searchable list; usually smaller.
 *   - {@see labelColumn()} — the one field that names the row in the result list.
 *
 * ## The invariant that makes the COUNT safe
 *
 * A column appears in `searchableColumns()` only if **every** viewer that entity's
 * `visibleTo()` scope admits may also read that column. That is stricter than "the searcher
 * may read it", and it has to be, because the number of results is itself a fact: if a term
 * could match on a field some viewers may not read, then two people with the same row access
 * would get different counts, and the difference is a statement about the hidden field. Keep
 * the vector to what everyone who can see the row can see, and a hit tells the searcher
 * nothing they could not already have read for themselves.
 *
 * The excluded columns, and the reason for each, are written out in the migration
 * `2026_10_20_0001_add_search_vectors_and_gin_indexes.php`. That migration's `VECTORS` array
 * and this enum's `searchableColumns()` are asserted to agree by
 * `tests/Feature/Search/SearchScopingTest` — two statements of one fact, with a test standing
 * between them, because the alternative is a vector that indexes a field this file thinks is
 * absent.
 *
 * ## Messages are the odd one out, on purpose
 *
 * `Message` has no vector and no columns here beyond its body. Phase 6 already built a scoped
 * message search — `ConversationService::search()`, scoping to the reader's own inbox before
 * matching (decision M-3) — and `SearchService` calls it. A second message matcher is exactly
 * the thing that slice's decision exists to prevent.
 */
enum SearchableType: string
{
    case Project = 'project';
    case Task = 'task';
    case Message = 'message';
    case Meeting = 'meeting';
    case Employee = 'employee';
    case Client = 'client';
    case File = 'file';
    case Income = 'income';
    case Expense = 'expense';

    /**
     * The group heading the palette renders, and the screen-reader group label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Project => 'Projects',
            self::Task => 'Tasks',
            self::Message => 'Messages',
            self::Meeting => 'Meetings',
            self::Employee => 'People',
            self::Client => 'Clients',
            self::File => 'Files',
            self::Income => 'Income',
            self::Expense => 'Expenses',
        };
    }

    /**
     * What one of these is, in one word.
     *
     * It is not decoration: the palette prefixes every result's accessible name with it
     * ("Project: Buffalo Modular — SEO"), because a screen-reader user moving through a flat
     * listbox does not hear the group heading again for every row, and "Buffalo Modular — SEO"
     * alone does not say whether it is a project, a file or a meeting.
     */
    public function singular(): string
    {
        return match ($this) {
            self::Project => 'Project',
            self::Task => 'Task',
            self::Message => 'Message',
            self::Meeting => 'Meeting',
            self::Employee => 'Person',
            self::Client => 'Client',
            self::File => 'File',
            self::Income => 'Income',
            self::Expense => 'Expense',
        };
    }

    /**
     * The table whose `search_vector` this type matches against, or null when the type is not
     * matched by tsvector at all.
     *
     * `Employee` names `users` rather than `employees`: a person is searched by the name they
     * are known by, and that name is a column of `users`. The QUERY still runs over
     * `employees` joined to `users`, so somebody with a user row and no employee row — which
     * is nobody today — is not a person the directory has ever listed.
     */
    public function vectorTable(): ?string
    {
        return match ($this) {
            self::Project => 'projects',
            self::Task => 'tasks',
            self::Meeting => 'meetings',
            self::Employee => 'users',
            self::Client => 'clients',
            self::File => 'files',
            self::Income => 'income',
            self::Expense => 'expenses',
            self::Message => null,
        };
    }

    /**
     * Every column in this type's `tsvector`, and therefore every column a search can match
     * on. Qualified with the table the vector lives on.
     *
     * Read the migration for why each excluded column is excluded. The short version, because
     * it is the thing most likely to be undone by a later change that means well:
     * `projects.internal_notes`, `clients.internal_notes`, `clients.contact_info`, the client's
     * name denormalised anywhere, and every money column are absent — a match on any of them
     * would tell a searcher that a phrase appears in a field they may not read.
     *
     * @return list<string>
     */
    public function searchableColumns(): array
    {
        return match ($this) {
            self::Project => ['name', 'domain', 'employee_notes'],
            self::Task => ['title', 'description', 'work_summary'],
            self::Meeting => ['title', 'agenda'],
            self::Employee => ['name'],
            self::Client => ['name'],
            self::File => ['name'],
            self::Income, self::Expense => ['notes'],

            // Not a vector. `ConversationService::search()` matches `messages.body` with
            // ILIKE inside the reader's own inbox; the column is named here so this enum is
            // still the one place that answers "what can a search of X match on".
            self::Message => ['body'],
        };
    }

    /**
     * The columns an excerpt may be cut from, in the order tried: the first one that contains
     * the term wins, and if none does, the first non-empty one is used.
     *
     * **Always a subset of {@see searchableColumns()}**, asserted by the scoping test. That is
     * not tidiness — it is the rule. A snippet drawn from a column that is not searchable
     * would be text the searcher gets to read *because* of a match caused by some other field,
     * which is the precise shape of the leak this list exists to prevent.
     *
     * Four types have an EMPTY list and that is the honest answer for them rather than an
     * oversight:
     *
     *   - `Client` and `Employee` are a name and nothing else. Their only searchable column is
     *     already the label, and an excerpt repeating it is noise at best. A client result
     *     carries a name; it carries no contact, no note and no count of projects, because
     *     decision 8-7 is about exactly that and a search result is not a second door to it.
     *   - `File` is a filename, same reason.
     *   - `Income` and `Expense` DO carry one: the note the record was filed under, which any
     *     holder of `finance.view` reads on the ledger anyway.
     *
     * @return list<string>
     */
    public function snippetColumns(): array
    {
        return match ($this) {
            self::Project => ['employee_notes', 'domain'],
            self::Task => ['description', 'work_summary'],
            self::Meeting => ['agenda'],
            self::Income, self::Expense => ['notes'],
            self::Message => ['body'],
            self::Client, self::Employee, self::File => [],
        };
    }

    /**
     * The column that names the row in the result list.
     *
     * `Employee` is `users.name` again, and `Income`/`Expense` have none: a money record is
     * named by its category and its amount, both of which are joins or numbers rather than a
     * text column, and `SearchService` composes that label itself.
     */
    public function labelColumn(): ?string
    {
        return match ($this) {
            self::Project, self::Client, self::File, self::Employee => 'name',
            self::Task, self::Meeting => 'title',
            self::Income, self::Expense, self::Message => null,
        };
    }

    /**
     * The order the palette renders the groups in.
     *
     * Work first, then the people and things around it, then the books. It is a fixed order
     * rather than a ranking across types on purpose: `ts_rank` scores are only comparable
     * WITHIN a vector — a project's `A`-weighted name hit and a task's `A`-weighted title hit
     * are the same number, and interleaving them would produce an order that changes under the
     * user for no reason they can see. Groups are stable; rows inside a group are ranked.
     *
     * @return list<self>
     */
    public static function inDisplayOrder(): array
    {
        return [
            self::Project,
            self::Task,
            self::Meeting,
            self::Message,
            self::Client,
            self::Employee,
            self::File,
            self::Income,
            self::Expense,
        ];
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Global search: one generated `search_vector` column per searchable table, and a GIN index
 * on each (master prompt Part D §17, Phase 10).
 *
 * ## The columns are GENERATED, not written by PHP and not maintained by a trigger
 *
 * The same argument decision 9-3 made for `payroll_items.net_salary`: the question is never
 * *store or compute*, it is **who computes it**, and the answer that makes the stored value
 * and the row impossible to disagree is the database, on write. A search index that is stale
 * is not a slow search, it is a **wrong** one — a project renamed away from "Buffalo" that
 * still answers to it is a privacy statement about a row the searcher was told they matched.
 *
 * A trigger would have worked and is rejected for the reason 9-3 rejects a service method: a
 * trigger is code with a body, it can be dropped, it can be `ALTER TABLE … DISABLE TRIGGER`d,
 * and it does not run for a `COPY` with `FREEZE` or a restore that disables triggers. A
 * generated column is part of the column definition: there is no write path — service, seeder,
 * factory, `psql`, a Phase 12 import — that can produce a row whose vector does not match its
 * own text, because PostgreSQL computes it after the write and nothing may supply it.
 *
 * `to_tsvector(regconfig, text)` is IMMUTABLE in its two-argument form (the one-argument form
 * reads `default_text_search_config` and is only STABLE), which is what makes it legal in a
 * generated expression at all. Every expression below therefore names its configuration.
 *
 * ## The configuration is `simple`, deliberately, and not `english`
 *
 * This agency writes in English and its DATA does not: it is client names (*Buffalo Modular
 * Homes*, *APH St Albans*), domains (*buffalomodular.com*, *heatgap.co.uk*), employee numbers
 * and project codes. Two properties of the `english` configuration are wrong for that corpus:
 *
 *   1. **It removes stop words.** `to_tsvector('english', 'The Gap')` is `'gap'`, and a search
 *      for a client genuinely called *The Gap* silently drops half of what the user typed. A
 *      search box that ignores a word you typed, without saying so, is worse than one that
 *      returns a near miss — and the failure is invisible, because an empty result set looks
 *      exactly like "no such project".
 *   2. **It stems, and a stemmer is a guess about English applied to proper nouns.** `'aphs'`
 *      and `'aph'` collapse together; so do `'heating'` and `'heat'`. Recall bought that way
 *      is paid for in false positives on names, which is the one field where a user knows
 *      exactly what they meant.
 *
 * What `english` would genuinely have bought — *meeting* finding *meetings* — is bought back
 * on the query side instead, by prefix-matching the terms the user typed (`meeting:*`). That
 * recovers the morphology that matters for a search-as-you-type box without the stemmer's
 * conflations, and it never removes a word. See `SearchService::tsquery()`.
 *
 * ## Weights are the ranking, and they are set here rather than at query time
 *
 * `A` is the row's NAME, `B` its secondary identifier, `C` its body. `ts_rank`'s default
 * weights ({D, C, B, A} = {0.1, 0.2, 0.4, 1.0}) then make a title hit outrank a body hit
 * without the query knowing which column matched — see `tests/Feature/Search/SearchRankingTest`.
 *
 * ## WHAT IS IN EACH VECTOR IS A PRIVACY DECISION, AND IT IS MADE HERE
 *
 * A column is in a vector only if **every** viewer who may see the row at all may also see
 * that column. That single invariant is what makes the result COUNT safe: if a match can only
 * ever be caused by text the matcher was already allowed to read, then "this row matched" tells
 * them nothing they did not already have. It is why these are absent and must stay absent:
 *
 *   - `projects.internal_notes` — Part C §2 gives the employee ❌. In the vector, a term that
 *     appears only in a project's internal notes would make that project a hit for an employee
 *     who is on it, and the hit is the leak: they learn the phrase is written in a field they
 *     may not read.
 *   - `projects.client_id` / the client's NAME denormalised onto the project — the same leak,
 *     and the one Phase 10's own security line names: *"search for 'Buffalo' as Tapu returns
 *     the project and never the client record or price"*. Tapu finds *Buffalo Modular — SEO*
 *     because the PROJECT is called that, not because its client is.
 *   - `clients.contact_info` — encrypted at rest (`encrypted:array`), so it could not be
 *     indexed even if it were allowed to be, and it is not.
 *   - `clients.internal_notes` — readable only with `clients.view_full`, which is not every
 *     viewer of a client row once a MANAGER holds the scoped 🟡 cell.
 *   - every money column, everywhere: `project_finance`, `income.amount`, `expenses.amount`,
 *     `payroll_items.*`, `employee_salaries.*`. Money is not text and is not searched.
 *
 * `app/Support/SearchableType.php` restates these lists in PHP, and
 * `tests/Feature/Search/SearchScopingTest` asserts the two agree. This docblock is the reason;
 * that enum is the single place a call site reads.
 *
 * ## `messages` deliberately gets no vector
 *
 * Part D §17 lists messages among the searchable entities and Phase 10's backend note lists
 * them among the tsvector tables. They are searched — by `ConversationService::search()`,
 * which Phase 6 built, which scopes to the reader's own inbox BEFORE matching (decision M-3),
 * and which matches with `ILIKE`. `SearchService` calls it rather than writing a second
 * message matcher.
 *
 * Adding a vector here would therefore have created a GIN index that nothing queries, on the
 * highest-insert-rate table in the application, paying write amplification on every message
 * posted for a read path that does not exist. Converting `ConversationService` to tsvector is
 * a change to a Phase 6 service and belongs to whoever owns that file; the column arrives with
 * it, not before it. Recorded as the one place this migration does not match Part D's table
 * list.
 */
return new class extends Migration
{
    /**
     * Every vector this migration creates: table => weighted expression over that table's own
     * columns.
     *
     * Written out as SQL rather than assembled from `SearchableType`, on purpose. A migration
     * is a historical record of what the schema became on a given day, and one that reads its
     * shape out of application code changes meaning the day that code is edited. The enum and
     * this array are asserted to agree by a test instead, which is the check that actually
     * catches a drift.
     *
     * @var array<string, string>
     */
    private const VECTORS = [
        // A project: what it is called, the domain that stands in for its client on the
        // employee surface (Part C §2), and the notes written FOR employees.
        'projects' => <<<'SQL'
            setweight(to_tsvector('simple', coalesce(name, '')), 'A') ||
            setweight(to_tsvector('simple', coalesce(domain, '')), 'B') ||
            setweight(to_tsvector('simple', coalesce(employee_notes, '')), 'C')
        SQL,

        // A task: its title, its brief, and the summary the assignee wrote when they finished
        // it. All three are unconditional on `TaskResource`, so all three are readable by
        // everyone `Task::visibleTo()` admits.
        'tasks' => <<<'SQL'
            setweight(to_tsvector('simple', coalesce(title, '')), 'A') ||
            setweight(to_tsvector('simple', coalesce(description, '')), 'C') ||
            setweight(to_tsvector('simple', coalesce(work_summary, '')), 'C')
        SQL,

        // A meeting: title and agenda. `meet_link` and `google_event_id` are excluded — a
        // joinable video link is not something to make findable by fragment.
        'meetings' => <<<'SQL'
            setweight(to_tsvector('simple', coalesce(title, '')), 'A') ||
            setweight(to_tsvector('simple', coalesce(agenda, '')), 'C')
        SQL,

        // A client: the name, and only the name. Contacts are ciphertext and internal notes
        // are not readable by every holder of the scoped view.
        'clients' => <<<'SQL'
            setweight(to_tsvector('simple', coalesce(name, '')), 'A')
        SQL,

        // A person: the name they are known by. Not the email (it is an address, and the
        // directory does not publish one), not the phone. Part H forbids anything that would
        // rank people, and this is the whole of what the Team directory itself carries.
        'users' => <<<'SQL'
            setweight(to_tsvector('simple', coalesce(name, '')), 'A')
        SQL,

        // A file: the name the uploader gave it. Not `path` — that is generated, not typed,
        // and is not what anybody remembers a file by.
        'files' => <<<'SQL'
            setweight(to_tsvector('simple', coalesce(name, '')), 'A')
        SQL,

        // Money in and money out: the note somebody filed it under. The amount is not text,
        // the category is a join (and editable, so a copy here would be a second one to keep
        // in step), and the date is a filter rather than a term.
        'income' => <<<'SQL'
            setweight(to_tsvector('simple', coalesce(notes, '')), 'A')
        SQL,

        'expenses' => <<<'SQL'
            setweight(to_tsvector('simple', coalesce(notes, '')), 'A')
        SQL,
    ];

    public function up(): void
    {
        foreach (self::VECTORS as $table => $expression) {
            // Added with raw SQL rather than through the Blueprint for the reason decision 9-3
            // added `net_salary` by hand: Laravel's `storedAs()` would write the same DDL, and
            // writing it out is what lets the expression be read and reviewed in one piece.
            DB::statement(sprintf(
                'ALTER TABLE %s ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (%s) STORED',
                $table,
                trim($expression),
            ));

            // GIN, not GiST: GIN is slower to update and far faster to search, and every one
            // of these tables is written a handful of times a day and read on every keystroke
            // of the command palette.
            DB::statement(sprintf(
                'CREATE INDEX %s_search_vector_gin ON %s USING gin (search_vector)',
                $table,
                $table,
            ));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::VECTORS) as $table) {
            // The index goes with the column; naming it here anyway so a partially applied
            // `up()` can still be reversed.
            DB::statement(sprintf('DROP INDEX IF EXISTS %s_search_vector_gin', $table));
            DB::statement(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS search_vector', $table));
        }
    }
};

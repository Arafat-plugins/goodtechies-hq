# Database checks

Read-only inspection dispatched to `dispatch-db-tester`. The default posture is **read-only**;
a write requires the user to ask for it in that turn, in words.

## Before dispatching

`AGENTS.md` should name the engine and the config file holding the connection. If it does
not, find the *file* once and add its path there — every future dispatch then gets it for
free:

```bash
grep -rliE "(DB_NAME|DATABASE_URL|DB_HOST|DB_USER|PGHOST|MONGO_URI|SQLITE)" \
  --include="*.env*" --include="*.php" --include="*.json" --include="*.yml" --include="*.yaml" \
  --include="*.toml" --include="*.ini" . | head
```

**`-l` is not optional.** Without it the matching lines — values included — print into your
context and the transcript. Never drop `-l`, never `cat` a matched file, never `grep` for the
password key without `-l`.

**Never put credentials in the brief.** Name the config file the agent should read them from.
A password pasted into a prompt is a password in a transcript.

**Never put credentials on a command line either.** Every tool call is recorded, and process
lists are readable. The brief tells the agent to pass them through files or the environment:

| Engine | Pass credentials via | Not |
| --- | --- | --- |
| MySQL / MariaDB | `mysql --defaults-extra-file=<(printf '[client]\nuser=%s\npassword="%s"\n' "$DB_RO_USER" "$DB_RO_PASSWORD")` — process substitution (bash, zsh), so no file is written; it must be the first option | `-p<password>` |
| PostgreSQL | `PGPASSFILE=<file>` / `~/.pgpass`, or `PGPASSWORD` exported from the config file, not typed | `postgres://user:pass@…` in the command |
| MongoDB | `mongosh "$MONGO_URI_RO"` with the read-only URI exported from the config file | the URI literal |
| SQLite | none needed | — |

**Loading the values without printing them.** An env file that is valid shell (values quoted
where they hold spaces, `#` or `$`): `set -a; . ./.env 2>/dev/null; set +a` — `2>/dev/null`
because a parse error echoes part of the offending value. Otherwise, or when unsure, one key
at a time: `export DB_RO_USER="$(grep -m1 '^DB_RO_USER=' .env | cut -d= -f2-)"`. Either way, **in the same Bash call as the query** — exports do not survive
to the next tool call in every runtime — and never `echo` them to check.

## Real read-only guards, not just instructions

The agent holds `Bash`; "read-only" is a rule it follows, not a wall. **The read-only database
user is the only real guard**: the server refuses its writes whatever the agent types. The rest
are **seatbelts** — they catch a slip, not a determined command:

| Engine | Guard (the user) | Seatbelts, on **every** invocation |
| --- | --- | --- |
| MySQL / MariaDB | the read-only user | `--init-command='SET SESSION TRANSACTION READ ONLY'`, and `--safe-updates` (blocks only `UPDATE`/`DELETE` without a key or `LIMIT` — `INSERT`, `DROP`, `ALTER` still run) |
| PostgreSQL | the read-only role | `PGOPTIONS='-c default_transaction_read_only=on' psql …` |
| SQLite | `sqlite3 -readonly <file>` — always; there is no reason to open it any other way | — |
| MongoDB | the read-only (`read` role) user | only `find`, `aggregate`, `countDocuments`, `explain`, `getIndexes` — no `insert*`, `update*`, `delete*`, `drop*`, `createIndex` |

**Every invocation, because each `psql -c` / `mysql -e` is a new connection.** A
`SET SESSION … READ ONLY` sent once ends with its process; the next command starts read-write.
And any session setting can be switched off by the same login (`SET default_transaction_read_only = off`).

**A read-only user is required, not preferred.** `AGENTS.md` → *Verification capabilities*
names it (`/dispatch setup`, step e, provisions it — see [setup.md](setup.md)).

**The db-tester refuses to proceed when the only credential it can find is the application's
read-write user**, and says what to run instead: the read-only-user SQL for the engine
(setup.md, step e — `/dispatch setup` prints it) and the env key to store it under. A
credential `AGENTS.md` does not name as read-only counts as read-write; the agent does not
connect with it to find out. SQLite is the one exception — `-readonly` is its guard.

So, before dispatching: *Verification capabilities* says `read-only user: none` → do not
dispatch; give the user the SQL and wait for their confirmation. Then confirm after the run:
`git status --porcelain` unchanged (the agent touched no files), and no DDL/DML in the queries
it reports.

## The brief

```
## Task
<the question being answered — not "check the database">

## Connection
Engine: <mysql | postgres | sqlite | mongo>.
Read-only user: <name>, credentials under <env key(s)> in <config file>.
Pass them via <option file / env var per the table>.
Do not print them in your output or in any command you quote.
If the only credential you can find is the application's read-write user, refuse to proceed:
connect with nothing, and report "Refused: no read-only credential" plus the setup.md step e
SQL to run for this engine.

## Posture
READ ONLY. Put the seatbelt from the table on every command you run: <PGOPTIONS=… | --init-command=… --safe-updates | -readonly>.
You may run SELECT, SHOW, DESCRIBE, EXPLAIN (or their equivalents below).
Do NOT run INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, CREATE, GRANT, or REPLACE.
Do NOT modify schema or data under any circumstances.

## Checks
<the specific questions>

## Report
A table of check → query run → result → judgement. At most 40 lines.
Say "cannot determine" where you cannot; do not guess.

## Out of scope
Do NOT survey the whole schema. Only what the checks above need.
Do NOT dump table contents; report counts and samples of at most 5 rows.
Do NOT print credentials, tokens, or personal data — mask them.

[ task list broken down into phases, each phase as a vertical slice, numbered ]
```

For a read-only brief a **vertical slice** is one check, taken end to end: the query, its
result, the judgement. Phase 1 = check 1, and so on. The report follows the phases.

## Per-engine equivalents

The brief says "SELECT, SHOW, DESCRIBE, EXPLAIN"; the agent translates:

| Need | MySQL / MariaDB | PostgreSQL (`psql`) | SQLite (`sqlite3`) | MongoDB (`mongosh`) |
| --- | --- | --- | --- | --- |
| List tables | `SHOW TABLES` | `\dt` or `information_schema.tables` | `.tables` | `db.getCollectionNames()` |
| Columns / types | `DESCRIBE t` | `\d t` or `information_schema.columns` | `.schema t`, `PRAGMA table_info(t)` | `db.t.findOne()` + `db.getCollectionInfos({name:"t"})` (validators) |
| Indexes | `SHOW INDEX FROM t` | `\di t*` or `pg_indexes` | `PRAGMA index_list(t)` | `db.t.getIndexes()` |
| Foreign keys | `information_schema.KEY_COLUMN_USAGE` | `\d t` (footer) | `PRAGMA foreign_key_list(t)` | none — check by query |
| Query plan | `EXPLAIN <q>` | `EXPLAIN (ANALYZE false) <q>` | `EXPLAIN QUERY PLAN <q>` | `db.t.find(q).explain("queryPlanner")` |
| Migration drift | compare to migration files | compare to migration files | `.schema` vs migration files | validators vs schema in code |

Never `EXPLAIN ANALYZE` a write, on any engine — it executes it.

## Checks worth asking for

**Schema integrity** — do the tables the code expects exist, with the expected columns and
types? Is anything the code writes to missing an index it is filtered by?

**Referential integrity** — orphaned rows whose parent is gone; foreign keys declared in code
but not in schema.

**Data sanity** — nulls in columns the code assumes non-null; duplicates in what should be
unique; timestamps in the future; counts that disagree between a table and its projection.

**Migration drift** — does the live schema match what the migration files describe? This is the
one that quietly breaks deploys, and it is invisible from reading code alone.

**Index health** — the columns the hot queries filter and sort by, versus the indexes that exist.
Pair with `EXPLAIN` on the actual query, not a guess at it.

## Reporting

The result is evidence, not a verdict on the code. Report the query, the result, and what it
implies. If a check suggests a bug, that is a new task — plan it, brief it, dispatch it. Do not
let the db agent fix what it found.

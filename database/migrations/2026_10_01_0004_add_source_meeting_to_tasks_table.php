<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an action item came from (master prompt Part D §20, `tasks … **Phase 7:**
     * `source_meeting_id` FK`; Part D §12, *"action items → **Convert to Task** (tasks get
     * `source_meeting_id`)"*).
     *
     * ## `nullOnDelete` is the whole design of this column
     *
     * The rule Part D §12 states for CANCELLING — *"linked tasks not deleted"* — has a stronger
     * sibling that nobody writes down until it goes wrong: **deleting the meeting must not
     * delete the task either.** An action item that came out of Tuesday's review is work
     * somebody owes, and it is owed whether or not the meeting row still exists. A cascade here
     * would mean an admin tidying up old calendar entries silently emptying somebody's board.
     *
     * So the FK is `nullOnDelete`: the task survives, and what it loses is only its *memory of
     * where it came from*. That is the right thing to lose, because there is nothing left to
     * link to.
     *
     * The column is **nullable and stays nullable for ever** — most tasks are not action items —
     * and it is **not fillable**. Provenance is set in the INSERT that creates the task and is
     * never editable afterwards, exactly as `recurring_task_id` is: see
     * `TaskService::BIRTH_FIELDS`, which this column joins. A task cannot be re-parented to a
     * different meeting by an edit form, because `update()` never looks at that list.
     *
     * ## Indexed, because it is read from both ends
     *
     * The meeting detail page asks "which tasks came out of this meeting", which is a lookup by
     * this column and would otherwise be a sequential scan of every task the agency has ever
     * had. Postgres does not index a foreign key column for you.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('source_meeting_id')
                ->nullable()
                ->after('recurring_period')
                ->constrained('meetings')
                ->nullOnDelete();

            $table->index('source_meeting_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['source_meeting_id']);
            $table->dropConstrainedForeignId('source_meeting_id');
        });
    }
};

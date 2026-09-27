<?php

use App\Support\MeetingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meetings (master prompt Part D §20: `meetings (title, start_at, end_at, project_id,
     * task_id, google_event_id, meet_link, organizer_id, agenda, status)`). Phase 7.
     *
     * Part D's line and no more than it — in particular there is **no `completed_at`, no
     * `reminder_sent_at` and no `cancelled_at`**, and each of those absences is a decision:
     *
     *   - *has it happened?* is `end_at < now()`. See `MeetingStatus` for why a stored copy of
     *     that is a second truth rather than a convenience.
     *   - *has the reminder gone out?* is a `meeting.reminder` row in `notifications`, asked
     *     through `NotificationService::alreadySentFor()` — the same memory `hq:flag-overdue`
     *     and `hq:notify-due-tomorrow` already use, and the reason a re-run, a restart or three
     *     days of downtime still send exactly one. A column would have been a third place to
     *     keep that fact.
     *   - *when was it cancelled?* is the `activity_logs` row `MeetingService::cancel()` writes,
     *     which also records WHO and, for the manual calendar driver, that the Meet itself still
     *     has to be called off by hand.
     *
     * ## What is a CONSTRAINT here rather than an `if` in PHP
     *
     *   - **`end_at > start_at`.** Strictly greater, not `>=`: a meeting of zero length is not a
     *     meeting, and every screen that draws a calendar block divides by the duration.
     *     `MeetingService` checks it first so the user gets a sentence instead of a 500 — but
     *     the promise is this CHECK, because the service is not the only caller a seeder, a
     *     console command or a later import will have.
     *   - **`status` is one of the two** `MeetingStatus` names, generated from the enum so the
     *     two cannot drift (decision 3-6's shape). This stops an unknown *word*; nothing here
     *     stops an illegal *move*, because with two statuses and one transition there is no
     *     machine to guard — `cancel()` is the only writer and it only ever writes `cancelled`.
     *   - **`google_event_id` is unique where it is present.** One remote calendar event belongs
     *     to at most one meeting. This is the constraint the second calendar driver will lean
     *     on: a retried `events.insert` that succeeded on the far side but timed out here must
     *     not be able to attach the same event to two rows. A partial index, because the
     *     manual driver leaves the column null on every row and NULLs are not unique to each
     *     other in Postgres anyway — the `WHERE` clause makes that explicit rather than
     *     incidental, and keeps the index the size of the rows that actually have one.
     *
     * **`meet_link`'s shape is checked in PHP, not here**, and that is deliberate: it is a
     * vendor's URL format. Frozen into a CHECK it would be a migration every time Google
     * changes a host or adds a path segment, on a table nobody could write to in the meantime.
     * `CalendarLink::looksLikeMeetLink()` owns it, one place, and the Form Request the
     * controller slice adds calls that same method rather than writing a second regex.
     *
     * ## The three foreign keys, and why they differ
     *
     *   - `project_id` and `task_id` are **`nullOnDelete`**. A meeting is about whatever it is
     *     about; if the task it was called for is deleted, the meeting still happened and its
     *     notes are still worth reading. Both are nullable in Part D's own line: a meeting may
     *     link a project, a task, both or neither.
     *   - `organizer_id` is **`restrictOnDelete`**. A meeting with no organiser has no editor
     *     and no policy answer — `MeetingPolicy::update()` asks "are you the organiser?" and a
     *     null cannot be. Nothing in this application deletes a user (leaving is
     *     `status = inactive`, Part D §21), and this is what keeps it that way rather than
     *     letting a future admin tool quietly orphan a row.
     */
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();

            $table->string('title');

            $table->timestamp('start_at');
            $table->timestamp('end_at');

            // A meeting may link a project, a task, both or neither (Part D §12).
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();

            // The calendar seam's two columns. Both stay null for the whole life of a meeting
            // under the manual driver unless the organiser pastes a link back; see
            // App\Services\Calendar\CalendarLink.
            $table->string('google_event_id')->nullable();
            $table->string('meet_link')->nullable();

            $table->foreignId('organizer_id')->constrained('users')->restrictOnDelete();

            $table->text('agenda')->nullable();

            $table->string('status')->default(MeetingStatus::Scheduled->value);

            $table->timestamps();

            // The reminder sweep and every "upcoming" list ask for scheduled meetings in a
            // narrow window of start_at; this index is the whole of both queries.
            $table->index(['status', 'start_at']);
            // The month and week calendars ask for everything OVERLAPPING a range, which is a
            // scan over both ends and not over one.
            $table->index(['start_at', 'end_at']);
            // "My meetings" for an organiser, and the policy's cheapest question.
            $table->index('organizer_id');
            // Postgres does not index a foreign key column for you, and the project and task
            // detail pages both list the meetings hanging off them.
            $table->index('project_id');
            $table->index('task_id');
        });

        $statuses = implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            MeetingStatus::values(),
        ));

        DB::statement("ALTER TABLE meetings ADD CONSTRAINT meetings_status_is_known CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE meetings ADD CONSTRAINT meetings_end_after_start CHECK (end_at > start_at)');
        DB::statement(
            'CREATE UNIQUE INDEX meetings_one_per_google_event ON meetings (google_event_id) '
            .'WHERE google_event_id IS NOT NULL',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};

<?php

use App\Support\RsvpStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who is in the room, and what they said (master prompt Part D §20:
     * `meeting_participants (meeting_id, user_id, rsvp_status)`). Phase 7.
     *
     * ## Unlike `conversation_members`, this table GRANTS something
     *
     * Decision 2-24 is emphatic that `conversation_members` is read state and grants nothing,
     * and that a conversation's audience is computed. This table is the opposite and the
     * difference is real rather than an inconsistency: a conversation's audience is *derivable*
     * from its subject ("whoever may see this task"), and a meeting's is not. There is no rule
     * that says who was invited to Thursday's client review — somebody chose, and the choice is
     * the data. `MeetingPolicy::view()` reads these rows because there is nothing else to read.
     *
     * The consequence is stated in `MeetingPolicy`: a participant may be in the room for a
     * meeting linked to a project they are not on, which is exactly why the linked project's
     * NAME is resolved per viewer and omitted rather than nulled (Part C §2, decisions C-1…C-7).
     *
     * ## The constraints
     *
     *   - **UNIQUE on `(meeting_id, user_id)`.** One row per person per meeting. Without it,
     *     an organiser double-tapping *Save* on a phone produces two rows and the participant
     *     is notified twice, counted twice and can hold two contradictory RSVPs. The service
     *     syncs through this index rather than reading-then-writing, so the race has nowhere
     *     to happen; a partial-order check in PHP would have a window between the SELECT and
     *     the INSERT, which is the trap decisions 3-1, 4-2 and 5-x each record once already.
     *   - **`rsvp_status` is one of the three** `RsvpStatus` names, generated from the enum.
     *   - **Both FKs cascade.** A participant row is meaningless without its meeting, and a
     *     user who is somehow deleted takes their own seat with them. Contrast
     *     `meetings.organizer_id`, which is `restrictOnDelete` because the meeting cannot be
     *     rendered without one.
     */
    public function up(): void
    {
        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('rsvp_status')->default(RsvpStatus::Pending->value);

            $table->timestamps();

            // One seat per person per meeting. The service's sync writes through this.
            $table->unique(['meeting_id', 'user_id'], 'meeting_participants_one_seat_each');
            // "My meetings": every list on every surface starts from this side.
            $table->index('user_id');
        });

        $statuses = implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            RsvpStatus::values(),
        ));

        DB::statement(
            "ALTER TABLE meeting_participants ADD CONSTRAINT meeting_participants_rsvp_is_known CHECK (rsvp_status IN ({$statuses}))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_participants');
    }
};

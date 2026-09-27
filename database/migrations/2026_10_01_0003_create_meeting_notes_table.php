<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What was said and what was decided (master prompt Part D §20:
     * `meeting_notes (meeting_id, notes, decisions)`). Phase 7.
     *
     * ## One row per meeting, enforced
     *
     * `meeting_id` is **UNIQUE**, not merely indexed. Part D §12 describes one pad of notes and
     * one list of decisions per meeting — *"notes + decisions recorded"* — so two rows is not a
     * richer model, it is a bug with two readers: the detail page would show one of them and
     * the next edit would grow the other. Two people closing a meeting at the same moment is
     * the ordinary case, not the exotic one, so the service writes through
     * `updateOrCreate(['meeting_id' => …])` and this index is what makes that atomic rather
     * than hopeful.
     *
     * It is a separate table rather than two columns on `meetings` because it is written by a
     * different act at a different time, usually by a different person, and because the vast
     * majority of rows in `meetings` will never have one: a 15-person agency schedules stand-ups
     * that nobody minutes. Two nullable `text` columns on the parent would be null on most rows
     * and would drag the whole of `meetings` — which the calendar reads in ranges — through the
     * TOAST pointer for them.
     *
     * ## Both columns are nullable, and both are free text
     *
     *   - **nullable**, because a meeting can have notes with no decisions ("we talked it
     *     through, nothing settled") and decisions with no notes ("approved the sitemap"). A
     *     row with one of the two is a real state and must not need a placeholder in the other.
     *   - **free text, not a list of action items.** The action items in Part D §12 do not live
     *     here: an action item *becomes a task*, and the task is where it lives, carrying
     *     `source_meeting_id` back to this meeting. There is no `meeting_action_items` table
     *     and there must not be one — it would be a second to-do list, invisible to the board,
     *     the calendar, My Tasks and every report.
     *
     * `cascadeOnDelete`: the notes are part of the meeting, and nothing about them survives it.
     * That is the opposite of a converted task, which survives on purpose — see
     * `2026_10_01_0004`.
     */
    public function up(): void
    {
        Schema::create('meeting_notes', function (Blueprint $table) {
            $table->id();

            // Unique on the FK itself: one pad per meeting, checked by the database.
            $table->foreignId('meeting_id')->unique()->constrained()->cascadeOnDelete();

            $table->text('notes')->nullable();
            $table->text('decisions')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_notes');
    }
};

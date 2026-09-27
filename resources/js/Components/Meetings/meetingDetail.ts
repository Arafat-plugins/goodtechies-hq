import type { Meeting } from '@/Components/Meetings/meetings';
import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * The detail page's payload: `MeetingResource` plus the five things only this screen needs.
 *
 * **A file of its own rather than an addition to `meetings.ts`.** That module is the contract
 * between the two Phase 7 slices and belongs to the one that wrote it; this describes what
 * `Shared\MeetingDetailController` adds on top, which nothing else in the phase reads. Every
 * shared word — the `Meeting` shape itself, `meetingRoutes()`, `RSVP_LABEL`, `RSVP_TONE`,
 * `meetingTimeRange()`, `meetingDuration()` — is imported from there and not restated here.
 *
 * The one rule worth repeating, because it survives being spread through a second interface:
 * **`project` and `task` are optional and are ABSENT, not null**, when the viewer may not see
 * them. `MeetingDetail extends Meeting`, so they stay optional; read them with `v-if` and never
 * write `meeting.project !== null`.
 */

/**
 * One task an action item became.
 *
 * A stub and not the whole task: a title, where it has got to, when it is due, who has it, and
 * the way to the task itself, which is where everything else about it lives. `href` is resolved
 * against the reader's own surface by the server and is `null` on a surface with no task screen
 * — a link to a route that does not exist is a 500 on a page somebody was only glancing at.
 */
export interface MeetingActionItem {
    id: number;
    title: string;
    status: string | null;
    status_label: string | null;
    status_tone: StatusKey | null;
    due_date: string | null;
    /** `3 Oct 2026`, written by the server in the agency's clock. */
    due_date_label: string | null;
    assignees: { id: number; name: string }[];
    href: string | null;
}

export interface MeetingDetail extends Meeting {
    /**
     * The pad. `null` here means *nothing written yet* and is a real state the screen says out
     * loud — it is not the Part C absence case, because everybody who can open the meeting can
     * read its notes.
     */
    notes: string | null;
    decisions: string | null;
    notes_updated_at: string | null;

    /** Only the ones this viewer may see. A converted task on a project they are not on is
     *  absent from the list and from its length. */
    action_items: MeetingActionItem[];

    /**
     * May this viewer add one?
     *
     * False on a cancelled meeting, false for somebody who may not create tasks, and false when
     * there is no linked project they can see — a task has to go on a project, and the convert
     * control puts it on the meeting's. The server answers all three; the screen never works it
     * out from `meeting.project` plus a role.
     */
    can_convert_action_items: boolean;

    /**
     * What to print instead of the form when they may not, and `null` when they may.
     *
     * The server's sentence rather than one composed here: only two of the three refusals may
     * share wording — "there is no project you can add to" covers both *no project* and *a
     * project you may not see*, because telling those apart is the fact Part C withholds — and
     * that judgement belongs beside the policy calls that made it, not in a ternary.
     */
    action_item_note: string | null;

    calendar: {
        /** Does the configured driver make its own Meet links? `false` is the manual driver. */
        creates_links: boolean;
        /**
         * What a human still has to do on Google after cancelling, or `null` when there is
         * nothing. The same sentence `MeetingService::cancel()` writes to the activity trail.
         */
        cancel_notice: string | null;
    };
}

/** The assignee picker's options, as the server sends them: an id and a name, nothing else. */
export interface MeetingAssignee {
    id: number;
    name: string;
}

/**
 * The three answers, in the order a person reads them.
 *
 * The order is a screen's business and lives here; the words and the tones are
 * `RSVP_LABEL` / `RSVP_TONE` in `meetings.ts`, which mirror `App\Support\RsvpStatus`. Nothing
 * here writes "Going" — that would be a third copy of a map that already exists twice for a
 * reason.
 */
export const RSVP_CHOICES = ['accepted', 'declined', 'pending'] as const;

<?php

namespace App\Http\Resources;

use App\Models\TimeEntry;
use App\Support\TimeEntryType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The only way a time entry leaves the server.
 *
 * ## Every state this payload reports is a word
 *
 * `state`, `state_label`, `entry_type_label`, `approval_label` and `flag_reason` are all text,
 * because the accessibility floor for this phase says no state may be carried by colour alone —
 * running, paused, flagged and awaiting approval each have to be readable without seeing a dot.
 * The screen prints these; it does not map a boolean onto a tint and hope.
 *
 * ## There is no score and no rate
 *
 * Not "efficiency", not "estimated vs tracked as a percentage", not a rank. Part H forbids
 * productivity scoring outright, and the grep test in `tests/Feature/Employee/TimeScreenTest.php`
 * fails if the strings "score" or "productivity" turn up in the Time UI. Tracked time is a
 * record, not a judgement.
 *
 * ## `elapsed_seconds` versus `duration_seconds`
 *
 * A finished entry reports `duration_seconds`, the fact written at stop. An open one reports
 * `elapsed_seconds` as of this response and leaves `duration_seconds` null — because an open
 * entry's length is a question about `now()` and the widget goes on counting from here. Nothing
 * ever adds a running entry into a stored total.
 *
 * @mixin TimeEntry
 */
class TimeEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            // The idempotency key travels back so the browser can match a replayed batch to the
            // row the server actually kept, which is how it learns the server won.
            'client_uuid' => $entry->client_uuid,

            'task' => $this->named($entry->task?->id, $entry->task?->title),
            'project' => $this->named($entry->project?->id, $entry->project?->name),

            'work_date' => $entry->work_date?->toDateString(),
            'started_at' => $entry->started_at?->toIso8601String(),
            'ended_at' => $entry->ended_at?->toIso8601String(),
            'paused_at' => $entry->paused_at?->toIso8601String(),
            'last_heartbeat_at' => $entry->last_heartbeat_at?->toIso8601String(),

            'paused_seconds' => $entry->pausedSeconds(),
            'duration_seconds' => $entry->duration_seconds,
            'elapsed_seconds' => $entry->elapsedSeconds(Carbon::now()),

            'state' => $entry->stateKey(),
            'state_label' => $this->stateLabel(),

            'entry_type' => $entry->entry_type->value,
            'entry_type_label' => $entry->entry_type->label(),

            // A flag is a fact with a reason, and the reason is what the screen prints. A row
            // flagged with no sentence is a row nobody investigates.
            'is_flagged' => $entry->isFlagged(),
            'flag_reason' => $entry->flag_reason,

            'counts' => $entry->counts(),
            'awaits_approval' => $entry->awaitsApproval(),
            'approval' => $entry->approvalKey(),
            'approval_label' => $this->approvalLabel(),

            // Why this entry is sitting in an Admin's queue, in sentences. See the method.
            'waiting_because' => $this->waitingBecause(),

            'reason' => $entry->reason,
            'edited_at' => $entry->edited_at?->toIso8601String(),
            'edited_by' => $this->whenLoaded('editor', fn (): ?string => $entry->editor?->name),

            // The refusal, kept on the row and shown to the person whose hours these are.
            // Rejecting is not deleting: the entry and its duration stay exactly where they
            // were, and this is the sentence saying why they do not count.
            'rejected_at' => $entry->rejected_at?->toIso8601String(),
            'rejected_by' => $this->whenLoaded('rejector', fn (): ?string => $entry->rejector?->name),
            'rejection_reason' => $entry->rejection_reason,

            'approved_at' => $entry->approved_at?->toIso8601String(),
            // Null with `approved_at` set means the system signed off an auto entry at stop.
            'approved_by' => $this->whenLoaded('approver', fn (): ?string => $entry->approver?->name),

            // Present only on the Admin queue, where the rows are other people's. The employee's
            // own Time page never loads the relation, so their payload does not carry their own
            // name back at them.
            'employee' => $this->whenLoaded('employee', fn (): array => [
                'id' => (int) $entry->employee->getKey(),
                'name' => $entry->employee->user?->name ?? 'Unknown',
            ]),

            'permissions' => $this->permissions($request),
        ];
    }

    /**
     * Why this entry is waiting, as a list of sentences — never a code and never a colour.
     *
     * The Admin's question in front of a queue is *why is this one here*, and there are three
     * answers, which can hold at once: it was typed in by hand, it was corrected after the fact,
     * or the watchdog flagged it. The flag brings its own sentence (`TimerFlag`, decision 4-6),
     * which is printed verbatim: an Admin who cannot read what the safeguard actually found has
     * no basis on which to approve or refuse.
     *
     * Empty for an entry that is not waiting, so an ordinary counted row wears no explanation.
     *
     * @return list<array{key: string, label: string, detail: string|null}>
     */
    private function waitingBecause(): array
    {
        $entry = $this->resource;
        $reasons = [];

        if ($entry->entry_type === TimeEntryType::Manual) {
            $reasons[] = [
                'key' => 'manual',
                'label' => 'Added by hand',
                'detail' => $entry->reason,
            ];
        }

        if ($entry->edited_at !== null) {
            $reasons[] = [
                'key' => 'edited',
                'label' => 'Edited after it was recorded',
                'detail' => $entry->edited_at->isoFormat('D MMM YYYY, HH:mm'),
            ];
        }

        if ($entry->isFlagged()) {
            $reasons[] = [
                'key' => 'flagged',
                'label' => 'Flagged by the timer',
                'detail' => $entry->flag_reason,
            ];
        }

        return $reasons;
    }

    private function stateLabel(): string
    {
        return match ($this->resource->stateKey()) {
            'running' => 'Running',
            'paused' => 'Paused',
            default => 'Stopped',
        };
    }

    /**
     * The approval state as a word, for the screens that must not carry it in a tint.
     *
     * Null for an ordinary counted row, so the employee's Time page renders no badge at all
     * rather than stamping "Approved" on every line of a fortnight. The two states that ARE
     * worth a word always get one — waiting, and refused — because both mean the hours are not
     * in the total and a row that only looked slightly different would not say so.
     */
    private function approvalLabel(): ?string
    {
        return match ($this->resource->approvalKey()) {
            'pending' => 'Waiting for approval',
            'rejected' => 'Turned down — not counted',
            default => null,
        };
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function named(?int $id, ?string $name): ?array
    {
        return $id === null ? null : ['id' => $id, 'name' => $name ?? '—'];
    }

    /**
     * What this requester may do with this entry — `TimeEntryPolicy`, resolved per record on the
     * server. The screen renders this and never derives one from a role (decisions 2-28, 2-31).
     *
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return ['can_update' => false];
        }

        return [
            'can_update' => Gate::forUser($user)->allows('update', $this->resource),
            // Resolved per record on the server, so the Admin queue draws Approve and Reject on
            // exactly the rows the endpoints would accept — never derived in Vue from a role
            // (decisions 2-28, 2-31). It is the same ability for both verbs: see the policy.
            'can_decide' => Gate::forUser($user)->allows('approve', $this->resource),
        ];
    }
}

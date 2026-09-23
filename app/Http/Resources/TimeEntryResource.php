<?php

namespace App\Http\Resources;

use App\Models\TimeEntry;
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
            'approval_label' => $this->approvalLabel(),

            'reason' => $entry->reason,
            'edited_at' => $entry->edited_at?->toIso8601String(),

            'permissions' => $this->permissions($request),
        ];
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
     * Null when there is nothing to say, so the screen renders no badge at all rather than a
     * badge reading "Approved" on every ordinary row.
     */
    private function approvalLabel(): ?string
    {
        return $this->resource->awaitsApproval() ? 'Waiting for approval' : null;
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

        return ['can_update' => Gate::forUser($user)->allows('update', $this->resource)];
    }
}

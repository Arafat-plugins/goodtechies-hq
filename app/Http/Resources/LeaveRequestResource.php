<?php

namespace App\Http\Resources;

use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * The only way a leave request leaves the server.
 *
 * ## The status word, the tone and the permissions are all resolved HERE
 *
 * `status_label` and `tone` come from `LeaveStatus`, on the server, because a second
 * status-to-colour map in a Vue computed drifts (decision 2-37) — and because DESIGN.md §1.4's
 * measurement makes the label mandatory rather than decorative: two of the eight status tones
 * are ΔE 0.16 apart under deuteranopia, so a bare dot is not a status.
 *
 * `permissions` is `LeaveRequestPolicy` answered per record, which is what decides whether the
 * queue draws Approve / Reject / Request correction on a row and whether My Leave draws the
 * resubmit form. Never a role read in Vue (decisions 2-28, 2-31) — and it is what keeps an
 * Admin from being shown an Approve button on their own request that the endpoint would refuse.
 *
 * ## The reason travels; the employee does not, unless the reader may see them
 *
 * `employee` is present only when the reader is not the applicant — on My Leave it would be
 * their own name on every row. The queue needs it, and the queue is already scoped by
 * `LeaveRequest::visibleTo()`, so a row that reaches this resource is a row its reader may see
 * in full.
 *
 * @mixin LeaveRequest
 */
class LeaveRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isOwn = $user?->employee !== null
            && (int) $user->employee->getKey() === (int) $this->resource->employee_id;

        return [
            'id' => (int) $this->id,

            'type' => $this->resource->leaveType?->toPayload(),

            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_one_day' => $this->resource->isOneDay(),

            // Working days on this employee's own schedule, counted once by
            // `LeaveService::leaveDays()` and stored. Never a difference between two dates.
            'days' => (int) $this->days,
            // What Phase 9 reads. Sent to every reader of the row because the person who asked
            // has to be able to see, before and after, that these days are unpaid.
            'unpaid_days' => (int) $this->unpaid_days,

            'reason' => $this->reason,

            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'tone' => $this->status?->tone(),

            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'approver' => $this->resource->approver === null ? null : [
                'id' => (int) $this->resource->approver->getKey(),
                'name' => (string) $this->resource->approver->name,
            ],

            'created_at' => $this->created_at?->toIso8601String(),

            // Absent on the reader's own rows — see the class docblock.
            'employee' => $isOwn ? null : $this->employeeFragment(),

            'permissions' => [
                'can_decide' => $user !== null && Gate::forUser($user)->allows('decide', $this->resource),
                'can_resubmit' => $user !== null && Gate::forUser($user)->allows('resubmit', $this->resource),
            ],
        ];
    }

    /**
     * Who asked. The name, the role and the employee number — the three things a queue row
     * needs to identify a person, and nothing else about them.
     *
     * @return array<string, mixed>|null
     */
    private function employeeFragment(): ?array
    {
        $employee = $this->resource->employee;

        if ($employee === null) {
            return null;
        }

        return [
            'id' => (int) $employee->getKey(),
            'name' => $employee->user?->name ?? 'Unknown',
            'role' => $employee->role?->name,
            'employee_number' => $employee->employee_number,
        ];
    }
}

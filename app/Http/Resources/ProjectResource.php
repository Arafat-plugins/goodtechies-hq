<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Models\User;
use App\Support\BillingFrequency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * The only way a project leaves the server (master prompt Part B §3 rule 1).
 *
 * Privacy is decided here, per field and per requester: a field the requester may not see is
 * **absent** from the array, never null and never masked, so a payload cannot leak a value by
 * shape. The route does not matter — the same resource answers the list, the detail page, a
 * task's project and a report.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'domain' => $this->domain,
            'project_type' => $this->project_type?->value,
            'project_type_label' => $this->project_type?->label(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'priority' => $this->priority?->value,
            'priority_label' => $this->priority?->label(),
            'start_date' => $this->start_date?->toDateString(),
            'deadline' => $this->deadline?->toDateString(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'is_archived' => $this->isArchived(),
            'employee_notes' => $this->employee_notes,
            'pm' => $this->pm(),
            'members' => $this->members(),
            'permissions' => $this->permissions($user),
        ];

        if ($user !== null && Gate::forUser($user)->allows('viewCommercial', $this->resource)) {
            $data['client'] = $this->client === null ? null : [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ];
            $data['internal_notes'] = $this->internal_notes;
        }

        if ($user !== null && Gate::forUser($user)->allows('viewFinance', $this->resource)) {
            $data['billing_type'] = $this->billing_type?->value;
            $data['billing_type_label'] = $this->billing_type?->label();
            $data['finance'] = $this->finance();
        }

        return $data;
    }

    /**
     * The project manager, named by their user record. The id is the employee id, as in members.
     *
     * @return array{id: int, name: string|null}|null
     */
    private function pm(): ?array
    {
        $pm = $this->resource->pm;

        return $pm === null ? null : [
            'id' => $pm->id,
            'name' => $pm->user?->name,
        ];
    }

    /**
     * The assigned employees. Read from the loaded relation only: a caller that wants members
     * eager-loads them, so a list of projects never turns into a query per row.
     *
     * @return list<array{id: int, name: string|null, role_on_project: string|null}>
     */
    private function members(): array
    {
        if (! $this->resource->relationLoaded('members')) {
            return [];
        }

        return $this->resource->members
            ->map(fn (Employee $member): array => [
                'id' => $member->id,
                'name' => $member->user?->name,
                'role_on_project' => $member->pivot?->role_on_project,
            ])
            ->values()
            ->all();
    }

    /**
     * Money, only ever reached through viewFinance. Null when the project has no finance row.
     *
     * @return array<string, mixed>|null
     */
    private function finance(): ?array
    {
        /** @var ProjectFinance|null $finance */
        $finance = $this->resource->finance;

        if ($finance === null) {
            return null;
        }

        $frequency = $finance->billing_frequency === null
            ? null
            : BillingFrequency::tryFrom($finance->billing_frequency);

        return [
            'price' => $finance->price,
            'recurring_amount' => $finance->recurring_amount,
            'billing_frequency' => $finance->billing_frequency,
            'billing_frequency_label' => $frequency?->label(),
            'contract_value' => $finance->contract_value,
            'contract_terms' => $finance->contract_terms,
            'profitability_snapshot' => $finance->profitability_snapshot,
        ];
    }

    /**
     * What the requester may do with this project, so the UI hides the controls it could not
     * use anyway. The UI is never the enforcement point; these mirror the policy.
     *
     * @return array<string, bool>
     */
    private function permissions(?User $user): array
    {
        if ($user === null) {
            return [
                'can_update' => false,
                'can_view_finance' => false,
                'can_manage_members' => false,
                'can_archive' => false,
            ];
        }

        $gate = Gate::forUser($user);

        return [
            'can_update' => $gate->allows('update', $this->resource),
            'can_view_finance' => $gate->allows('viewFinance', $this->resource),
            'can_manage_members' => $gate->allows('manageMembers', $this->resource),
            'can_archive' => $gate->allows('archive', $this->resource),
        ];
    }
}

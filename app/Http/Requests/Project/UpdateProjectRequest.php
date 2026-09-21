<?php

namespace App\Http\Requests\Project;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Editing a project. Members and finance are their own endpoints — each is guarded by a
 * different ability — so they are not accepted here.
 *
 * Neither is `status`: it moves only through the status route (which enforces the legal
 * transitions and keeps cancelling and reopening to an Admin) or through archive/unarchive
 * (which own `archived_at`). A `status` sent here is not a validation error, it is ignored.
 */
class UpdateProjectRequest extends StoreProjectRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->projectRules();
    }
}

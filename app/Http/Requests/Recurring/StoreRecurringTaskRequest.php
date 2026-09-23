<?php

namespace App\Http\Requests\Recurring;

/**
 * A new retainer template on a project. Every field is RecurringTaskRequest's.
 *
 * The project is NOT a field: it is the route's `{project}`, which is what the policy scopes
 * against. A `project_id` in the body would be a second way to say where a template lives, and
 * a second way for it to land somewhere the requester was not authorised for.
 */
class StoreRecurringTaskRequest extends RecurringTaskRequest {}

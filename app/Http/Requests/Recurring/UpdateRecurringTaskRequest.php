<?php

namespace App\Http\Requests\Recurring;

/**
 * An edit to an existing template. Every field is RecurringTaskRequest's, and all of them are
 * required, because the editor sends the whole form back.
 *
 * The project is deliberately not among them: a template belongs to the project whose retainer
 * it describes, and moving one would silently re-point a standing instruction at another
 * client's work. Somebody who wants the same retainer on a second project makes a second
 * template, which is also what makes each project's generation log its own.
 */
class UpdateRecurringTaskRequest extends RecurringTaskRequest {}

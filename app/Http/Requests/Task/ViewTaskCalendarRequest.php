<?php

namespace App\Http\Requests\Task;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The window a calendar grid is asking for.
 *
 * The Calendar is a deep link — `/admin/tasks/calendar?date_from=…&date_to=…` is what the
 * month arrows push into the address bar, and what a bookmark keeps — so its one piece of new
 * input gets a Form Request like any other input, rather than a controller deciding what a
 * date means. A window that ends before it starts is a mistake worth naming, not a grid that
 * renders empty and leaves the user wondering where their tasks went.
 *
 * Only the window is validated here. The chips a view carries across — search, project,
 * status, priority, assignee, tag, overdue, archived — arrive exactly as they do on the List
 * and the Board, and TaskService::filters() drops what it does not recognise rather than
 * failing a page load over a hand-edited query string.
 *
 * Both ends are optional and each stands alone. Absent, the window is the current month;
 * `date_from` alone is that date to the end of its month, and `date_to` alone is from the
 * start of its. TaskService::window() is where that is decided, because a job asking for a
 * window gets the same answer as a browser.
 */
class ViewTaskCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_from.date' => 'The calendar window has to start on a real date.',
            'date_to.date' => 'The calendar window has to end on a real date.',
            'date_to.after_or_equal' => 'A calendar window has to end on or after it starts.',
        ];
    }
}

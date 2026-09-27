<?php

namespace App\Http\Requests\Task;

use App\Support\GanttZoom;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The window and the ruling a Gantt is asking for.
 *
 * Deliberately the same shape as `ViewTaskCalendarRequest`, with one field added. Both views
 * open a WINDOW on the same query, both put it in the query string so the view is an address,
 * and both would otherwise be a controller quietly deciding what a date means. A fourth view
 * that invented its own window vocabulary would be a fourth thing to keep in step with
 * `TaskService::filters()`, so `date_from` / `date_to` are spelled exactly as the Calendar
 * spells them and the view switcher can carry them across.
 *
 * `zoom` is the one addition, and it is not a filter: it never changes which tasks come back,
 * only how wide the default window is and how the header above the bars is ruled. An
 * unrecognised zoom is refused here rather than silently defaulted, because unlike a stale
 * `?status=` chip it is a value this screen writes itself — a `zoom=quarter` in an address bar
 * is a typo worth naming, and `GanttZoom::resolve()` still defaults for callers that are not
 * HTTP.
 */
class ViewTaskGanttRequest extends FormRequest
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
            'zoom' => ['nullable', 'string', Rule::in(GanttZoom::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_from.date' => 'The Gantt window has to start on a real date.',
            'date_to.date' => 'The Gantt window has to end on a real date.',
            'date_to.after_or_equal' => 'A Gantt window has to end on or after it starts.',
            'zoom.in' => 'A Gantt is ruled by day, week or month.',
        ];
    }
}

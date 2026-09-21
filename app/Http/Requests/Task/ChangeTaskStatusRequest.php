<?php

namespace App\Http\Requests\Task;

use App\Support\TaskStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a task along the status machine — the one request the form, the board drag and the
 * calendar drag all use.
 *
 * That is the whole point: there is a single endpoint per surface, taking a single request,
 * calling a single service method, through a single door in the model's status guard. A drag
 * cannot be given softer rules than the form because there is nowhere else to put them.
 *
 * `after_id` is what a drag carries and a form does not: the card the drop landed under, so
 * the move and the placing happen in one transaction instead of two requests that can half-fail.
 */
class ChangeTaskStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(TaskStatus::class)],

            // Submitting for review and completing both need one; the service decides which
            // moves require it and whose it has to be, because that depends on the task.
            'work_summary' => ['nullable', 'string', 'max:10000'],

            // Cancelling and reopening are the two moves nobody makes silently. The service
            // refuses them without a reason; the length limit is this request's business.
            'reason' => ['nullable', 'string', 'max:500'],

            'after_id' => ['nullable', 'integer', 'exists:tasks,id'],
        ];
    }

    public function status(): TaskStatus
    {
        return TaskStatus::from($this->validated('status'));
    }

    public function workSummary(): ?string
    {
        return $this->text('work_summary');
    }

    public function reason(): ?string
    {
        return $this->text('reason');
    }

    public function afterId(): ?int
    {
        $id = $this->validated('after_id');

        return $id === null ? null : (int) $id;
    }

    private function text(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

<?php

namespace App\Http\Requests\Task;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A drag that moves a card inside its own column.
 *
 * The client sends the card it was dropped under, not a position: positions are the server's
 * arithmetic (sparse multiples of 1000, midpoint on drop, renumber the column when the
 * midpoints run out) and a client that sent one could put two cards on the same number.
 *
 * `after_id` absent or null means the top of the column.
 */
class ReorderTaskRequest extends FormRequest
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
            'after_id' => ['nullable', 'integer', 'exists:tasks,id'],
        ];
    }

    public function afterId(): ?int
    {
        $id = $this->validated('after_id');

        return $id === null ? null : (int) $id;
    }
}

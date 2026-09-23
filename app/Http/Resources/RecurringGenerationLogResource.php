<?php

namespace App\Http\Resources;

use App\Models\RecurringGenerationLog;
use App\Models\Task;
use App\Support\GenerationOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attempt at one template for one period, as the generation log reads it.
 *
 * The screen's whole job here is to be legible: an admin opens this tab once a month, and only
 * ever because something looks wrong. So every row leaves the server already spelled — the
 * period as *October 2026*, the outcome as a label, and the row itself as **one sentence**.
 * Nothing is left for Vue to assemble out of an enum value and two nullable ids.
 *
 * `sentence` is the engine's own `message` wherever the engine wrote one, which is every
 * interesting case: the duplicate ("An instance for October 2026 already exists (task #41)."),
 * the stop ("The project is Cancelled; recurring generation stops when…"), the previous-period
 * warning. It is composed here only for the boring row the engine left silent — a clean
 * generation — because "Generated" with nothing after it is not a sentence either.
 *
 * @mixin RecurringGenerationLog
 */
class RecurringGenerationLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'period' => $this->period,
            // Derived from the KEY alone, so a row keeps its label whatever became of the
            // template's rule afterwards.
            'period_label' => $this->resource->periodLabel(),

            'outcome' => $this->outcome?->value,
            'outcome_label' => $this->outcome?->label(),
            // Every skip is a warning, and so is a generation that happened while the previous
            // period was still open — the model answers both halves in one place.
            'is_warning' => $this->resource->isWarning(),

            'message' => $this->message,
            'sentence' => $this->sentence(),

            // Named and located, never serialised whole: a log row is not a back door onto a
            // task payload. The engine's own message already carries the number; this is what
            // makes the row clickable.
            'task' => $this->taskStub($this->resource->task),
            'previous_open_task' => $this->taskStub($this->resource->previousOpenTask),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The row as a person reads it.
     *
     * The engine writes a sentence whenever there is something to say, and this prefers it
     * every time — the wording in the log and the wording on the screen have to be the same
     * wording, or somebody comparing a support question against the database finds two stories.
     */
    private function sentence(): string
    {
        $message = trim((string) $this->resource->message);

        if ($message !== '') {
            return $message;
        }

        $period = $this->resource->periodLabel() ?? (string) $this->resource->period;

        return match ($this->resource->outcome) {
            GenerationOutcome::Generated => sprintf('Generated the instance for %s.', $period),
            // The four skips all carry a message from the engine, so these are only ever
            // reached by a row written before this screen existed. They still have to read as
            // something rather than as an empty cell.
            null => sprintf('An attempt was recorded for %s.', $period),
            default => sprintf('%s — %s.', $this->resource->outcome->label(), $period),
        };
    }

    /**
     * @return array{id: int, title: string}|null
     */
    private function taskStub(?Task $task): ?array
    {
        return $task === null ? null : ['id' => $task->id, 'title' => $task->title];
    }
}

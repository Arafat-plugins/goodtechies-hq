<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Task;
use App\Support\ConversationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * A task discussion, because that is the only type Phase 2 builds — and the CHECK
     * constraint on `conversations` insists a `task` row carries a task, so the default has to
     * make one rather than leave the link null.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ConversationType::Task,
            'linked_project_id' => null,
            'linked_task_id' => Task::factory(),
            'title' => null,
        ];
    }

    public function forTask(Task $task): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ConversationType::Task,
            'linked_project_id' => null,
            'linked_task_id' => $task->getKey(),
        ]);
    }

    /**
     * One of the four types Phase 6 owns. Nothing in the application creates these; the state
     * exists so a test can prove that ConversationPolicy denies them.
     */
    public function ofType(ConversationType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'linked_project_id' => null,
            'linked_task_id' => null,
            'title' => ucfirst($type->value).' channel',
        ]);
    }
}

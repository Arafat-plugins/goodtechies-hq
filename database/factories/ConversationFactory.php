<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\ConversationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * A task discussion — the commonest kind, and the one whose CHECK constraint insists the
     * row carries a task, so the default has to make one rather than leave the link null.
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
            'dm_one_id' => null,
            'dm_two_id' => null,
        ]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ConversationType::Project,
            'linked_project_id' => $project->getKey(),
            'linked_task_id' => null,
            'dm_one_id' => null,
            'dm_two_id' => null,
        ]);
    }

    /**
     * A DM between two people.
     *
     * The pair is SORTED here as well as in `ConversationService::dmBetween()`, because the
     * table's CHECK insists `dm_one_id < dm_two_id` and a factory that produced rows the
     * application could not is a factory that tests the wrong thing.
     */
    public function betweenPeople(User $one, User $two): static
    {
        $ids = [(int) $one->getKey(), (int) $two->getKey()];
        sort($ids);

        return $this->state(fn (array $attributes): array => [
            'type' => ConversationType::Dm,
            'linked_project_id' => null,
            'linked_task_id' => null,
            'dm_one_id' => $ids[0],
            'dm_two_id' => $ids[1],
            'title' => null,
        ]);
    }

    /**
     * A channel with no linked object: `team` or `announcement`.
     *
     * Both are singletons behind a partial unique index, so a test that wants one asks the
     * service for it (`ConversationService::team()`); this state is for the cases that want to
     * build one by hand on a database that has none.
     */
    public function channel(ConversationType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'linked_project_id' => null,
            'linked_task_id' => null,
            'dm_one_id' => null,
            'dm_two_id' => null,
            'title' => ucfirst($type->value),
        ]);
    }

    /**
     * Any type, with whatever links that type requires filled in.
     *
     * Kept because Phase 2's tests call it; it now produces a row the CHECK accepts for every
     * one of the five types rather than only for the ones that need no link.
     */
    public function ofType(ConversationType $type): static
    {
        return match ($type) {
            ConversationType::Task => $this->forTask(Task::factory()->create()),
            ConversationType::Project => $this->forProject(Project::factory()->create()),
            ConversationType::Dm => $this->betweenPeople(
                User::factory()->create(),
                User::factory()->create(),
            ),
            ConversationType::Team, ConversationType::Announcement => $this->channel($type),
        };
    }
}

<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Notifications for tests and for the permission matrix, which needs a row to point its
 * `…/{notification}/read` cells at.
 *
 * Nothing in the application uses this: a real notification comes into existence through
 * NotificationService and through nothing else. A factory is a test fixture, not a second door.
 *
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = $this->faker->sentence(4);

        return [
            'user_id' => User::factory(),
            'type' => NotificationType::TaskAssigned->value,
            'payload' => [
                'title' => $title,
                'target' => null,
                'actor' => null,
                'context' => [],
            ],
            // Unique per row by default, so two factory notifications never accidentally group.
            'group_key' => NotificationType::TaskAssigned->value.':fixture:'.$this->faker->unique()->numberBetween(1, 1_000_000),
            'count' => 1,
            'is_read' => false,
            'read_at' => null,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }

    public function ofType(NotificationType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type->value,
            'group_key' => $type->value.':fixture:'.$this->faker->unique()->numberBetween(1, 1_000_000),
        ]);
    }

    /**
     * Point the notification at a real record, so its deep link resolves.
     */
    public function about(Model $target): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => [
                ...(array) $attributes['payload'],
                'target' => ['type' => $target->getMorphClass(), 'id' => (int) $target->getKey()],
            ],
            'group_key' => sprintf(
                '%s:%s:%d',
                (string) $attributes['type'],
                $target->getMorphClass(),
                (int) $target->getKey(),
            ),
        ]);
    }

    /**
     * Already read. The read pair is set together because the table's CHECK constraint refuses
     * a row that carries one without the other.
     */
    public function read(): static
    {
        return $this->state(fn (): array => ['is_read' => true, 'read_at' => now()]);
    }
}

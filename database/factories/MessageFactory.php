<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'author_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }

    public function inConversation(Conversation $conversation): static
    {
        return $this->state(fn (array $attributes): array => [
            'conversation_id' => $conversation->getKey(),
        ]);
    }

    public function by(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'author_id' => $user->getKey(),
        ]);
    }
}

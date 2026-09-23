<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\File;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    /**
     * A row only. Nothing is written to the disk unless a test asks with `withContents()`, so
     * a test that never downloads anything never leaves a byte behind.
     *
     * A file has to have exactly one owner — the `files_one_owner` CHECK says so — and the
     * default is a task, because that is the surface most of these tests are about.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $extension = 'pdf';

        return [
            'task_id' => Task::factory(),
            'project_id' => null,
            'client_id' => null,
            'message_id' => null,
            'disk' => config('filesystems.default'),
            'path' => 'files/tasks/'.Str::ulid().'.'.$extension,
            'name' => Str::slug(fake()->words(2, true)).'.'.$extension,
            'extension' => $extension,
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 512 * 1024),
            'checksum' => hash('sha256', (string) Str::uuid()),
            'uploaded_by' => User::factory(),
            'version_of' => null,
            'version' => 1,
        ];
    }

    /**
     * Hang the file off this record instead of a task, clearing the other two owner columns so
     * the CHECK constraint is satisfied whichever order the states are applied in.
     */
    public function ownedBy(Model $owner): static
    {
        $column = File::OWNERS[$owner::class] ?? 'task_id';

        return $this->state(fn (array $attributes): array => [
            'task_id' => null,
            'project_id' => null,
            'client_id' => null,
            'message_id' => null,
            $column => $owner->getKey(),
        ]);
    }

    public function forTask(Task $task): static
    {
        return $this->ownedBy($task);
    }

    public function forProject(Project $project): static
    {
        return $this->ownedBy($project);
    }

    public function forClient(Client $client): static
    {
        return $this->ownedBy($client);
    }

    /** A message attachment — the fourth owner, added with the task discussion. */
    public function forMessage(Message $message): static
    {
        return $this->ownedBy($message);
    }

    public function uploadedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => ['uploaded_by' => $user->getKey()]);
    }

    public function name(string $name): static
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $this->state(fn (array $attributes): array => [
            'name' => $name,
            'extension' => $extension,
            'path' => 'files/'.Str::ulid().'.'.$extension,
        ]);
    }

    public function mimeType(string $mimeType): static
    {
        return $this->state(fn (array $attributes): array => ['mime_type' => $mimeType]);
    }

    /** An older version of `$root`, already stood down. */
    public function supersededVersion(File $root, int $version = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'version_of' => $root->chainId(),
            'version' => $version,
            'superseded_at' => now(),
        ]);
    }

    /**
     * Put real bytes behind the row, so a download has something to stream. Tests that use this
     * are expected to have called Storage::fake() first.
     */
    public function withContents(string $contents = 'the bytes'): static
    {
        return $this->afterCreating(function (File $file) use ($contents): void {
            Storage::disk($file->disk)->put($file->path, $contents);
        });
    }
}

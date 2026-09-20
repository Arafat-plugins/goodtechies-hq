<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes and reads the human-readable per-object timeline (activity_logs).
 */
class ActivityLogger
{
    public function record(Model $object, string $description, ?User $actor = null): ActivityLog
    {
        $actor ??= auth()->user();

        return ActivityLog::create([
            'object_type' => $object->getMorphClass(),
            'object_id' => $object->getKey(),
            'actor_id' => $actor?->getKey(),
            'description' => $description,
        ]);
    }

    /**
     * The object's timeline, newest first.
     *
     * @return Collection<int, ActivityLog>
     */
    public function for(Model $object): Collection
    {
        return ActivityLog::query()
            ->where('object_type', $object->getMorphClass())
            ->where('object_id', $object->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}

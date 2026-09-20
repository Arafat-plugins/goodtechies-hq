<?php

namespace App\Services;

use App\Models\User;
use App\Support\Permission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Lists and revokes a user's sessions (database session driver).
 */
class SessionService
{
    /**
     * @return Collection<int, array{id: string, ip_address: string|null, user_agent: string|null, last_active_at: Carbon, is_current: bool}>
     */
    public function forUser(User $user, ?string $currentSessionId): Collection
    {
        return $this->table()
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'last_active_at' => Carbon::createFromTimestamp($row->last_activity),
                'is_current' => $currentSessionId !== null && hash_equals($row->id, $currentSessionId),
            ])
            ->values();
    }

    /**
     * The owner may revoke their own other sessions; a holder of roles.manage may revoke anyone's.
     * The current session cannot be revoked here — logging out ends it.
     *
     * @param  string|null  $currentSessionId  defaults to the current request's session id
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ModelNotFoundException
     */
    public function revoke(User $actor, User $owner, string $sessionId, ?string $currentSessionId = null): void
    {
        $isOwner = $actor->is($owner);

        if (! $actor->isActive() || (! $isOwner && ! $actor->hasPermission(Permission::RolesManage))) {
            throw new AuthorizationException('You are not allowed to end this session.');
        }

        $currentSessionId ??= request()->hasSession() ? request()->session()->getId() : null;

        if ($currentSessionId !== null && hash_equals($sessionId, $currentSessionId)) {
            throw new LogicException('The current session cannot be revoked; log out instead.');
        }

        $deleted = $this->table()
            ->where('id', $sessionId)
            ->where('user_id', $owner->id)
            ->delete();

        if ($deleted === 0) {
            throw (new ModelNotFoundException)->setModel('session', [$sessionId]);
        }
    }

    private function table(): Builder
    {
        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'));
    }
}

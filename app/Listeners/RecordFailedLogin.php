<?php

namespace App\Listeners;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Auth\Events\Failed;

/**
 * Failed attempts go to login_history only; they are not audit events.
 */
class RecordFailedLogin
{
    public function handle(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;
        $email = is_string($email) ? $email : null;

        $user = $event->user instanceof User
            ? $event->user
            : ($email === null ? null : User::whereRaw('lower(email) = ?', [mb_strtolower($email)])->first());

        LoginHistory::create([
            'user_id' => $user?->id,
            'email' => $email,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'succeeded' => false,
        ]);
    }
}

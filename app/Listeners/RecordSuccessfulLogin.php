<?php

namespace App\Listeners;

use App\Models\LoginHistory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\AuditEvent;
use Illuminate\Auth\Events\Login;

class RecordSuccessfulLogin
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        LoginHistory::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'succeeded' => true,
        ]);

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->audit->record(AuditEvent::UserLogin, $user, null, ['guard' => $event->guard], $user);
    }
}

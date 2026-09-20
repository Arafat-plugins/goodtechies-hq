<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The only writer of audit_logs. The table is append-only for hq_app.
 */
class AuditLogger
{
    public function record(
        AuditEvent $event,
        ?Model $target = null,
        mixed $old = null,
        mixed $new = null,
        ?User $actor = null,
    ): AuditLog {
        return $this->recordFor(
            $event,
            $target?->getMorphClass(),
            $target === null ? null : (int) $target->getKey(),
            $old,
            $new,
            $actor,
        );
    }

    /**
     * Record an event for a target that has no model instance (e.g. a setting key or a route).
     */
    public function recordFor(
        AuditEvent $event,
        ?string $targetType,
        ?int $targetId,
        mixed $old = null,
        mixed $new = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= auth()->user();
        $request = $this->currentRequest();

        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            'event' => $event->value,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'old_value' => $old,
            'new_value' => $new,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    /**
     * The HTTP request being handled, if any. Console commands and queue workers get a synthetic
     * request that the router never matched, so a request without a route counts as none.
     */
    private function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return $request->route() === null ? null : $request;
    }
}

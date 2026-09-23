<?php

namespace App\Models;

use App\Support\NotificationTab;
use App\Support\NotificationType;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's mail.
 *
 * Written only through App\Services\NotificationService — the engine is the one door, the way
 * AuditLogger is for `audit_logs`. Nothing else in the application constructs one of these, and
 * a second writer would be a second place the §11 dedup rule could be got wrong.
 *
 * A notification belongs to exactly one user and is never shared, which is why every read goes
 * through `forUser()` and a row belonging to somebody else answers 404 rather than 403: there
 * is nothing to refuse, because from the requester's side it does not exist.
 */
#[Fillable([])]
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    /**
     * How many rows the bell asks for. Small on purpose: the bell is a glance, the Center is
     * the list, and a poll every 15 seconds by every signed-in user should not be paying for
     * fifty rows nobody is going to look at.
     */
    public const BELL_LIMIT = 10;

    /** The Notification Center's page size. */
    public const PAGE_SIZE = 20;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'payload' => 'array',
            'count' => 'integer',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * This person's notifications and nobody else's. Every read starts here.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    /**
     * Newest first, with `id` making the sort total so two rows written in the same second
     * never swap places between two polls.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * The tab's types, as a filter. `All` lists every type, so it narrows nothing — the query
     * has one shape whichever tab was asked for.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeOnTab(Builder $query, NotificationTab $tab): Builder
    {
        if ($tab === NotificationTab::All) {
            return $query;
        }

        return $query->whereIn('type', array_map(
            fn (NotificationType $type): string => $type->value,
            $tab->types(),
        ));
    }

    /**
     * The dedup candidate: an UNREAD row for this group key, written inside the window.
     *
     * Unread is half the rule and the half that is easy to miss. A row the person has already
     * looked at must not quietly absorb the next event — they would never be told about it —
     * so reading a group closes it and the next event starts a new one. See
     * NotificationService::deliver().
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeGroupable(Builder $query, string $groupKey, Carbon $since): Builder
    {
        return $query->unread()
            ->where('group_key', $groupKey)
            ->where('created_at', '>=', $since);
    }

    public function summary(): string
    {
        return $this->type?->summary($this->payload ?? [], (int) $this->count) ?? '';
    }

    /**
     * The record this notification is about, as the payload recorded it: the morph class and
     * the id. Null when the payload predates or omits it.
     *
     * @return array{type: string, id: int}|null
     */
    public function target(): ?array
    {
        $target = $this->payload['target'] ?? null;

        if (! is_array($target) || ! isset($target['type'], $target['id'])) {
            return null;
        }

        return ['type' => (string) $target['type'], 'id' => (int) $target['id']];
    }
}

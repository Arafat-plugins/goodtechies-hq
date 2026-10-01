<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One device a person turned push notifications on for (Profile).
 */
#[Fillable(['user_id', 'endpoint', 'public_key', 'auth_token', 'content_encoding', 'user_agent'])]
#[Hidden(['auth_token', 'public_key'])]
class PushSubscription extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

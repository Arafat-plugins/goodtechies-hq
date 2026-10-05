<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One installed goodERP Android app's Firebase token, and whose phone it is right now.
 * See the `app_push_tokens` migration and `RememberAppPushToken`.
 */
#[Fillable(['user_id', 'token'])]
#[Hidden(['token'])]
class AppPushToken extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

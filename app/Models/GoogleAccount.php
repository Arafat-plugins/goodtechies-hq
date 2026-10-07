<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Polish 030: the Google account goodERP creates Meet links with. One row at most; the tokens
 * are encrypted at rest and never leave the server (`$hidden`).
 *
 * @property int $id
 * @property string|null $email
 * @property string $refresh_token
 * @property string|null $access_token
 * @property Carbon|null $access_token_expires_at
 * @property int|null $connected_by
 */
#[Fillable(['email', 'refresh_token', 'access_token', 'access_token_expires_at', 'connected_by'])]
class GoogleAccount extends Model
{
    /** @var list<string> */
    protected $hidden = ['refresh_token', 'access_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return self::query()->latest('id')->first();
    }
}

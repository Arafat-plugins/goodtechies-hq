<?php

namespace App\Models;

use App\Support\ClientStatus;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client of the agency. Contact people are encrypted at rest; project money lives in
 * ProjectFinance, never here.
 *
 * contact_info shape (not enforced, documented for callers):
 * list<array{name: string, role: string, email: string, phone: string}>
 */
#[Fillable(['name', 'internal_notes', 'status'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contact_info' => 'encrypted:array',
            'status' => ClientStatus::class,
        ];
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}

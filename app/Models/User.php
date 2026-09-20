<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Permission as PermissionKey;
use App\Support\RoleName;
use App\Support\Surface;
use App\Support\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'timezone', 'status'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The permission keys granted through the user's role, loaded once per instance.
     *
     * @var list<string>|null
     */
    private ?array $permissionKeys = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'status' => UserStatus::class,
        ];
    }

    /**
     * @return HasOne<Employee, $this>
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * The user's role, taken from their employee record.
     */
    public function role(): ?RoleName
    {
        return $this->employee?->role?->name;
    }

    /**
     * Whether the user's role holds the permission key. Scope checks live in Policies.
     */
    public function hasPermission(PermissionKey $permission): bool
    {
        if ($this->permissionKeys === null) {
            $roleId = $this->employee?->role_id;

            $this->permissionKeys = $roleId === null
                ? []
                : Permission::query()
                    ->join('role_permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                    ->where('role_permissions.role_id', $roleId)
                    ->pluck('permissions.key')
                    ->map(fn (PermissionKey|string $key): string => $key instanceof PermissionKey ? $key->value : $key)
                    ->all();
        }

        return in_array($permission->value, $this->permissionKeys, true);
    }

    /**
     * The UI shell the user's role belongs to.
     */
    public function surface(): ?Surface
    {
        return Surface::forRole($this->role());
    }

    public function hasRole(RoleName ...$roles): bool
    {
        return in_array($this->role(), $roles, true);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Admins and Accountants must use two-factor authentication.
     */
    public function requiresTwoFactor(): bool
    {
        return $this->hasRole(RoleName::ADMIN, RoleName::ACCOUNTANT);
    }
}

<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Clients\Tenancy;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use HasRoles { hasAnyPermission as protected hasAnyAssignedPermission;
        checkPermissionTo as protected checkAssignedPermissionTo; }

    public function checkPermissionTo($permission, ?string $guardName = null): bool
    {
        $tenant = app(Tenancy::class);
        if (! $tenant->usable($this)) {
            return false;
        }
        $name = is_string($permission) ? $permission : ($permission->name ?? null);
        if ($tenant->clientId($this) !== null && ! in_array($name, Tenancy::CLIENT_ABILITIES, true)) {
            return false;
        }

        return $this->checkAssignedPermissionTo($permission, $guardName);
    }

    public function hasAnyPermission(...$permissions): bool
    {
        if ($this->clientMembership()->exists()) {
            return collect($permissions)->flatten()->contains(fn ($permission) => $this->can($permission));
        }

        return $this->isSuperAdministrator() || $this->hasAnyAssignedPermission(...$permissions);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'auth_version' => 'integer',
            'password' => 'hashed',
        ];
    }

    public function clientMembership()
    {
        return $this->hasOne(ClientMembership::class);
    }

    public function isSuperAdministrator(): bool
    {
        return ! $this->clientMembership()->exists() && $this->hasRole('Super Administrator');
    }
}

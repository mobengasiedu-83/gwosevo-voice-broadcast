<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Clients\AccountContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use HasRoles {
        hasAnyPermission as protected hasAnyAssignedPermission;
        hasPermissionTo as protected hasAssignedPermissionTo;
    }

    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        return ! $this->isClient() && $this->hasAssignedPermissionTo($permission, $guardName);
    }

    public function hasAnyPermission(...$permissions): bool
    {
        return ! $this->isClient() && ($this->isSuperAdministrator() || $this->hasAnyAssignedPermission(...$permissions));
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
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function ($user) {
            if ($user->getOriginal('client_account_id') !== null && $user->isDirty('client_account_id')) {
                throw new \LogicException('Client users cannot be reassigned between accounts.');
            }
        });
    }

    public function clientAccount()
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function isClient(): bool
    {
        return $this->client_account_id !== null || $this->hasRole('Client');
    }

    public function clientAccountActive(): bool
    {
        return $this->client_account_id !== null && app(AccountContext::class)->active((int) $this->client_account_id);
    }

    public function isSuperAdministrator(): bool
    {
        return ! $this->isClient() && $this->hasRole('Super Administrator');
    }
}

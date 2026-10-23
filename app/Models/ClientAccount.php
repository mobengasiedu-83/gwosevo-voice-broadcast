<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ClientAccount extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn ($account) => $account->uuid = (string) Str::uuid());
        static::addGlobalScope('client_visibility', function ($query) {
            $user = auth()->user();
            if ($user?->isClient()) {
                $query->where('client_accounts.id', $user->client_account_id)->where('status', 'active');
            }
        });
    }

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime'];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}

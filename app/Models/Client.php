<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Client extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['profile' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn ($c) => $c->uuid ??= (string) Str::uuid());
        static::saving(function ($c) {
            abort_unless(in_array($c->status, ['pending', 'active', 'suspended', 'closed'], true), 422);
        });
    }

    public function scopeWithDisplayEmail($query)
    {
        return $query->addSelect('clients.*')->selectSub(
            ClientMembership::query()->join('users', 'users.id', '=', 'client_memberships.user_id')
                ->whereColumn('client_memberships.client_id', 'clients.id')
                ->where('client_memberships.status', 'active')
                ->whereNotNull('users.email')->where('users.email', '!=', '')
                ->orderByRaw("CASE WHEN client_memberships.role = 'owner' THEN 0 ELSE 1 END")
                ->orderBy('client_memberships.id')->limit(1)->select('users.email'),
            'login_email'
        );
    }

    public function getDisplayEmailAttribute(): string
    {
        return trim($this->profile['contact_email'] ?? '') ?: ($this->login_email ?: 'No email');
    }

    public function memberships()
    {
        return $this->hasMany(ClientMembership::class);
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    public function imports()
    {
        return $this->hasMany(ContactImport::class);
    }

    public function recordings()
    {
        return $this->hasMany(AudioFile::class);
    }

    public function wallet()
    {
        return $this->hasOne(WalletAccount::class);
    }

    public function walletReservations()
    {
        return $this->hasManyThrough(WalletReservation::class, WalletAccount::class, 'client_id', 'wallet_id');
    }

    public function disputes()
    {
        return $this->hasMany(BillingDispute::class);
    }

    public function payments()
    {
        return $this->hasMany(PaymentIntent::class);
    }
}

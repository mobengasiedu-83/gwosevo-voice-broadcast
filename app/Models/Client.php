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

    public function memberships()
    {
        return $this->hasMany(ClientMembership::class);
    }
    public function campaigns() { return $this->hasMany(Campaign::class); }
    public function contacts() { return $this->hasMany(Contact::class); }
    public function imports() { return $this->hasMany(ContactImport::class); }
    public function recordings() { return $this->hasMany(AudioFile::class); }

}

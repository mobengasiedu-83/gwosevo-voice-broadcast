<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientMembership extends Model
{
    protected $guarded = ['id'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::saving(function ($m) {
            abort_unless(in_array($m->status, ['active', 'revoked'], true) && in_array($m->role, ['owner', 'member'], true), 422);
            abort_if($m->exists && $m->isDirty(['user_id', 'client_id']), 422, 'Account membership identity cannot be reassigned.');
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientAuthChallenge extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['secret_hash', 'code_hash'];

    protected function casts(): array
    {
        return ['details' => 'array', 'verified_at' => 'datetime', 'consumed_at' => 'datetime', 'sent_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}

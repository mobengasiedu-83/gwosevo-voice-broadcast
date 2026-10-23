<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactConsent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}

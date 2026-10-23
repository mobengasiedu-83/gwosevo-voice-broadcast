<?php

namespace App\Models;

use App\Models\Concerns\ClientChild;
use Illuminate\Database\Eloquent\Model;

class ContactConsent extends Model
{
    use ClientChild;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}

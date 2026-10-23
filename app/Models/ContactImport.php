<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactImport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array'];
    }
}

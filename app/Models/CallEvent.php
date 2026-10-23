<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}

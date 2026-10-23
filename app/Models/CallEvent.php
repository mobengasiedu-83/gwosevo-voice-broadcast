<?php

namespace App\Models;

use App\Models\Concerns\ClientChild;
use Illuminate\Database\Eloquent\Model;

class CallEvent extends Model
{
    use ClientChild;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}

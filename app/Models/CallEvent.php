<?php

namespace App\Models;

use App\Models\Concerns\ScopedThroughClient;
use Illuminate\Database\Eloquent\Model;

class CallEvent extends Model
{
    use ScopedThroughClient;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioFile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'deployed_at' => 'datetime', 'duration' => 'float', 'rejected_at' => 'datetime'];
    }
}

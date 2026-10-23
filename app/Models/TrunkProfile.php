<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrunkProfile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allowed_caller_ids' => 'array', 'max_channels' => 'integer', 'reserved_channels' => 'integer', 'safe_channels' => 'integer', 'max_cps' => 'float', 'active' => 'boolean', 'verified_at' => 'datetime'];
    }
}

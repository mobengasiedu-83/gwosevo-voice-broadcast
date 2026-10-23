<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferRoute extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}

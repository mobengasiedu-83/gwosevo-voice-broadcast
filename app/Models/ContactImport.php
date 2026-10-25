<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

class ContactImport extends Model
{
    use BelongsToClient;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array'];
    }
}

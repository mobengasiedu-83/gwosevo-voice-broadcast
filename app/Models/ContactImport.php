<?php

namespace App\Models;

use App\Models\Concerns\ClientOwned;
use Illuminate\Database\Eloquent\Model;

class ContactImport extends Model
{
    use ClientOwned;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array'];
    }
}

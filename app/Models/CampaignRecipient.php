<?php

namespace App\Models;

use App\Models\Concerns\ScopedThroughClient;
use Illuminate\Database\Eloquent\Model;

class CampaignRecipient extends Model
{
    use ScopedThroughClient;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }
}

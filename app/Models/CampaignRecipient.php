<?php

namespace App\Models;

use App\Models\Concerns\ClientChild;
use Illuminate\Database\Eloquent\Model;

class CampaignRecipient extends Model
{
    use ClientChild;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }
}

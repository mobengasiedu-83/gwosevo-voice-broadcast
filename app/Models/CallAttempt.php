<?php

namespace App\Models;

use App\Models\Concerns\ScopedThroughClient;
use Illuminate\Database\Eloquent\Model;

class CallAttempt extends Model
{
    use ScopedThroughClient;

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['ivr_completed_at' => 'datetime', 'opted_out_at' => 'datetime', 'transfer_started_at' => 'datetime', 'transfer_answered_at' => 'datetime', 'sent_at' => 'datetime', 'answered_at' => 'datetime', 'playback_started_at' => 'datetime', 'playback_completed_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}

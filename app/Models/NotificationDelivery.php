<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Observable email/SMS delivery for one personal notification. */
class NotificationDelivery extends Model
{
    public const CHANNELS = ['email', 'sms'];

    public const STATUSES = ['queued', 'sent', 'failed', 'skipped'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function notification()
    {
        return $this->belongsTo(UserNotification::class, 'notification_id');
    }
}

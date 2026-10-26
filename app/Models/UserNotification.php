<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A personal account alert. Visibility is always by user_id, never by tenant
 * filters alone, so a member cannot see a colleague's copy of an alert.
 */
class UserNotification extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'in_app' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function deliveries()
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_id');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}

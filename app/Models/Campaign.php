<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Campaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'scheduled_at' => 'datetime', 'archived_at' => 'datetime', 'submitted_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->uuid = (string) Str::uuid());
    }

    public function versions()
    {
        return $this->hasMany(CampaignVersion::class);
    }
}

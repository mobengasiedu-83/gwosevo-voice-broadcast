<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Contact extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->uuid = (string) Str::uuid());
    }

    public function consents()
    {
        return $this->hasMany(ContactConsent::class);
    }

    public function groups()
    {
        return $this->belongsToMany(ContactGroup::class);
    }
}

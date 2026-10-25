<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

class ContactGroup extends Model
{
    use BelongsToClient;

    protected $guarded = ['id'];

    protected $attributes = ['active' => true, 'category' => 'General'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function contacts()
    {
        return $this->belongsToMany(Contact::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\ClientOwned;
use Illuminate\Database\Eloquent\Model;

class ContactGroup extends Model
{
    use ClientOwned;

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

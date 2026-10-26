<?php

namespace App\Models;

use App\Models\Concerns\ScopedThroughClient;
use Illuminate\Database\Eloquent\Model;

class BillingException extends Model
{
    use ScopedThroughClient;

    public $timestamps = false;

    protected $guarded = ['id'];
}

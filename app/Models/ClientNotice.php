<?php

namespace App\Models;

use App\Services\Clients\AccountContext;
use Illuminate\Database\Eloquent\Model;

class ClientNotice extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope('client_account', function ($query) {
            $context = app(AccountContext::class);
            if (($id = $context->id()) !== null) {
                $query->where('client_notices.client_account_id', $id);
                if (! $context->active($id)) {
                    $query->whereRaw('1 = 0');
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}

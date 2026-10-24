<?php

namespace App\Models;

use App\Services\Clients\AccountContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ReportExport extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['storage_key', 'checksum'];

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->uuid = (string) Str::uuid());
        static::updating(function ($model) {
            if ($model->isDirty(['uuid', 'user_id', 'client_account_id', 'kind', 'format', 'filters', 'report_schedule_id', 'scheduled_for'])) {
                throw new \LogicException('Export ownership and requested filters are immutable.');
            }
        });
        static::addGlobalScope('client_account', function ($q) {
            if (($id = app(AccountContext::class)->id()) !== null) {
                $q->where('report_exports.client_account_id', $id);
                if (! app(AccountContext::class)->active($id)) {
                    $q->whereRaw('1 = 0');
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['filters' => 'array', 'expires_at' => 'datetime', 'completed_at' => 'datetime', 'scheduled_for' => 'datetime'];
    }
}

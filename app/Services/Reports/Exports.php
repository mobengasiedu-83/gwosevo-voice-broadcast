<?php

namespace App\Services\Reports;

use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Alerts\Notifications;
use App\Services\Foundation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class Exports
{
    public function create(User $user, array $filters, string $format, ?int $schedule = null, $scheduledFor = null): ReportExport
    {
        $filters = app(Analytics::class)->filters($user, $filters);
        Validator::make(compact('format'), ['format' => 'required|in:csv,xlsx'])->validate();

        return DB::transaction(function () use ($user, $filters, $format, $schedule, $scheduledFor) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($schedule && ($existing = ReportExport::where('report_schedule_id', $schedule)->where('scheduled_for', $scheduledFor)->first())) {
                return $existing;
            }
            if (ReportExport::where('user_id', $user->id)->where('created_at', '>=', now()->subHour())->count() >= 10) {
                throw ValidationException::withMessages(['export' => 'Limit reached: ten exports per hour.']);
            }
            $export = ReportExport::create(['user_id' => $user->id, 'client_account_id' => $user->client_account_id,
                'kind' => $filters['kind'], 'format' => $format, 'filters' => $filters, 'report_schedule_id' => $schedule,
                'scheduled_for' => $scheduledFor, 'expires_at' => now()->addDays(config('reports.retention_days'))]);
            app(Foundation::class)->audit($user, 'report.export_requested', $export, ['kind' => $export->kind, 'format' => $format]);
            GenerateReportExport::dispatch($export->id)->afterCommit();

            return $export;
        });
    }

    public function access(User $user, ReportExport $export): void
    {
        abort_unless((int) $export->user_id === (int) $user->id && $export->client_account_id === $user->client_account_id, 404);
        app(Analytics::class)->filters($user, $export->filters);
    }

    public static function csvCell($value)
    {
        if (is_string($value) && preg_match('/\A[\s\x00-\x1f]*[=+\-@]/u', $value)) {
            return "'".$value;
        }

        return $value;
    }

    public function generate(int $id): void
    {
        $export = DB::transaction(function () use ($id) {
            $row = ReportExport::whereKey($id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending') {
                return null;
            }
            if ($row->expires_at->isPast()) {
                $row->update(['status' => 'expired']);

                return null;
            }
            $row->update(['status' => 'processing']);

            return $row;
        });
        if (! $export) {
            return;
        }
        $key = 'reports/'.$export->uuid.'.'.$export->format;
        $sheet = null;
        $handle = null;
        try {
            $user = User::findOrFail($export->user_id);
            $this->access($user, $export);
            $analytics = app(Analytics::class);
            $query = $analytics->query($user, $export->filters);
            $maximum = (int) config('reports.max_export_rows');
            if ((clone $query)->count() > $maximum) {
                throw new RuntimeException('row_limit');
            }
            Storage::disk('local')->makeDirectory('reports');
            $path = Storage::disk('local')->path($key);
            $headers = $analytics->headers($export->kind);
            if ($export->format === 'csv') {
                $handle = fopen($path, 'wb');
                if (! $handle) {
                    throw new RuntimeException('storage');
                }
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, $headers, ',', '"', '');
            } else {
                $sheet = new Spreadsheet;
                $sheet->getActiveSheet()->fromArray($headers);
                $sheet->getActiveSheet()->freezePane('A2');
                $sheet->getActiveSheet()->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($headers)).'1')->getFont()->setBold(true);
            }
            $count = 0;
            $order = match ($export->kind) {
                'campaigns' => 'c.id', 'attempts' => 'a.id', 'billing' => 'l.id', 'ivr' => 'd.id'
            };
            foreach ($query->orderBy($order)->limit($maximum + 1)->cursor() as $row) {
                if (++$count > $maximum) {
                    throw new RuntimeException('row_limit');
                }
                $values = $analytics->row($export->kind, $row);
                if ($handle) {
                    fputcsv($handle, array_map([self::class, 'csvCell'], $values), ',', '"', '');
                } else {
                    foreach ($values as $column => $value) {
                        $sheet->getActiveSheet()->setCellValueExplicit([$column + 1, $count + 1], $value ?? '', is_int($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    }
                }
            }
            if ($handle) {
                fclose($handle);
                $handle = null;
            } else {
                (new Xlsx($sheet))->save($path);
                $sheet->disconnectWorksheets();
                $sheet = null;
            }
            $this->access($user->fresh(), $export);
            if ($export->expires_at->isPast()) {
                throw new RuntimeException('expired');
            }
            Log::channel('reports')->info('Report generated.', ['export_id' => $export->uuid, 'rows' => $count, 'format' => $export->format]);
            $export->update(['status' => 'completed', 'storage_key' => $key, 'checksum' => hash_file('sha256', $path), 'row_count' => $count, 'completed_at' => now(), 'failure_reason' => null]);
            if ($export->client_account_id) {
                app(Notifications::class)->notice($export->client_account_id, 'export:'.$export->uuid, 'report.ready', 'Report export ready',
                    'Your '.$export->kind.' report is ready in Reports & exports. Download it before its seven-day retention period ends.', 'info', $export->user_id);
            }
        } catch (\Throwable $e) {
            Log::channel('reports')->warning('Report generation failed.', ['export_id' => $export->uuid, 'exception_class' => get_class($e)]);
            if (is_resource($handle)) {
                fclose($handle);
            }
            $sheet?->disconnectWorksheets();
            Storage::disk('local')->delete($key);
            $export->update(['status' => 'failed', 'failure_reason' => $e->getMessage() === 'row_limit'
                ? 'Export exceeds the row limit. Narrow the dates or filters.' : 'Export could not be generated. Check access, filters and storage, then request a new export.']);
        }
    }

    public function schedule(User $user, array $filters, array $input): void
    {
        $filters = app(Analytics::class)->filters($user, $filters);
        $data = Validator::make($input, ['format' => 'required|in:csv,xlsx', 'frequency' => 'required|in:daily,weekly', 'local_time' => 'required|date_format:H:i', 'lookback_days' => 'required|integer|in:7,30,90'])->validate();
        DB::transaction(function () use ($user, $filters, $data) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (DB::table('report_schedules')->where('user_id', $user->id)->where('active', true)->count() >= 5) {
                throw ValidationException::withMessages(['schedule' => 'You may have at most five active schedules.']);
            }
            $id = DB::table('report_schedules')->insertGetId($data + ['user_id' => $user->id, 'client_account_id' => $user->client_account_id,
                'kind' => $filters['kind'], 'timezone' => $filters['timezone'], 'filters' => json_encode($filters, JSON_THROW_ON_ERROR),
                'active' => true, 'next_run_at' => $this->next($data['local_time'], $filters['timezone'], $data['frequency']), 'created_at' => now(), 'updated_at' => now()]);
            app(Foundation::class)->audit($user, 'report.schedule_created', $user, ['schedule_id' => $id, 'kind' => $filters['kind'], 'frequency' => $data['frequency']]);
        });
    }

    private function next(string $time, string $zone, string $frequency): CarbonImmutable
    {
        $now = CarbonImmutable::now($zone);
        $candidate = CarbonImmutable::parse($now->format('Y-m-d').' '.$time, $zone);
        if ($candidate->lte($now)) {
            $candidate = $candidate->addDays($frequency === 'weekly' ? 7 : 1);
        }

        return $candidate->utc();
    }

    public function maintain(): void
    {
        DB::table('report_schedules')->where('active', true)->where('next_run_at', '<=', now())->orderBy('id')->limit(50)->get()->each(function ($candidate) {
            try {
                DB::transaction(function () use ($candidate) {
                    $s = DB::table('report_schedules')->where('id', $candidate->id)->lockForUpdate()->first();
                    if (! $s->active || now()->lt($s->next_run_at)) {
                        return;
                    }
                    $user = User::findOrFail($s->user_id);
                    $end = CarbonImmutable::parse($s->next_run_at)->timezone($s->timezone)->subDay()->format('Y-m-d');
                    $filters = json_decode($s->filters, true, 512, JSON_THROW_ON_ERROR);
                    $filters['to'] = $end;
                    $filters['from'] = CarbonImmutable::parse($end, $s->timezone)->subDays($s->lookback_days - 1)->format('Y-m-d');
                    $this->create($user, $filters, $s->format, $s->id, $s->next_run_at);
                    DB::table('report_schedules')->where('id', $s->id)->update(['next_run_at' => $this->next($s->local_time, $s->timezone, $s->frequency), 'updated_at' => now()]);
                });
            } catch (\Throwable $e) {
                DB::table('report_schedules')->where('id', $candidate->id)->update(['active' => false, 'updated_at' => now()]);
            }
        });
        ReportExport::where('expires_at', '<=', now())->where('status', '!=', 'expired')->orderBy('id')->chunkById(100, function ($exports) {
            foreach ($exports as $e) {
                Storage::disk('local')->delete($e->storage_key ?? 'reports/'.$e->uuid.'.'.$e->format);
                $e->update(['status' => 'expired', 'storage_key' => null, 'checksum' => null]);
            }
        });
        ReportExport::where('status', 'processing')->where('updated_at', '<', now()->subMinutes(30))->chunkById(100, function ($exports) {
            foreach ($exports as $e) {
                Storage::disk('local')->delete($e->storage_key ?? 'reports/'.$e->uuid.'.'.$e->format);
                $e->update(['status' => 'failed', 'failure_reason' => 'Generation was interrupted; request a new export.']);
            }
        });
    }
}

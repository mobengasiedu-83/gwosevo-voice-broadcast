<?php

namespace App\Http\Controllers;

use App\Models\ReportExport;
use App\Services\Clients\Portal;
use App\Services\Foundation;
use App\Services\Reports\Analytics;
use App\Services\Reports\Exports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    private function route(Request $request): string
    {
        return $request->user()->isClient() ? 'client.reports' : 'reports.index';
    }

    public function index(Request $request, Analytics $analytics)
    {
        $user = $request->user();
        $filters = $analytics->filters($user, $request->query());
        $query = $analytics->query($user, $filters);
        $order = match ($filters['kind']) {
            'campaigns' => 'c.id', 'attempts' => 'a.id', 'billing' => 'l.id', 'ivr' => 'd.id'
        };
        $data = ['filters' => $filters, 'rows' => $query->orderByDesc($order)->paginate(25)->withQueryString(),
            'headers' => $analytics->headers($filters['kind']), 'analytics' => $analytics, 'summary' => $analytics->summary($user, $filters),
            'routeName' => $this->route($request),
            'exports' => ReportExport::where('user_id', $user->id)->latest('id')->paginate(5, ['*'], 'exports_page')->withQueryString(),
            'schedules' => DB::table('report_schedules')->where('user_id', $user->id)->latest('id')->limit(100)->get(),
            'maxRows' => config('reports.max_export_rows')];
        if ($user->isClient()) {
            $data += ['account' => $user->clientAccount()->firstOrFail(), 'unread' => app(Portal::class)->unread($user)];
        }

        return response()->view($user->isClient() ? 'reports.client' : 'reports.internal', $data)->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, Analytics $analytics, Exports $exports)
    {
        $data = $request->validate(['format' => 'required|in:csv,xlsx']);
        $filters = $analytics->filters($request->user(), $request->all());
        $exports->create($request->user(), $filters, $data['format']);

        return redirect()->route($this->route($request), $filters)->with('status', 'Export queued. It will appear in your private export history when ready.');
    }

    public function download(Request $request, ReportExport $export, Exports $exports)
    {
        $exports->access($request->user(), $export);
        abort_unless($export->status === 'completed' && $export->expires_at->isFuture(), 410, 'This export is unavailable or expired. Request a new export.');
        abort_unless($export->storage_key && Storage::disk('local')->exists($export->storage_key), 404);
        $path = Storage::disk('local')->path($export->storage_key);
        abort_unless($export->checksum && hash_equals($export->checksum, hash_file('sha256', $path)), 409, 'Export integrity check failed. Request a new export.');
        app(Foundation::class)->audit($request->user(), 'report.export_downloaded', $export);

        return response()->download($path, 'voicecast-'.$export->kind.'-'.$export->uuid.'.'.$export->format, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => $export->format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function schedule(Request $request, Analytics $analytics, Exports $exports)
    {
        $filters = $analytics->filters($request->user(), $request->all());
        $exports->schedule($request->user(), $filters, $request->all());

        return redirect()->route($this->route($request), $filters)->with('status', 'Report schedule created. Scheduled exports appear in your private history.');
    }

    public function stop(Request $request, int $schedule, Analytics $analytics)
    {
        $row = DB::table('report_schedules')->where('id', $schedule)->where('user_id', $request->user()->id)->first();
        abort_unless($row, 404);
        $analytics->authorize($request->user(), $row->kind);
        DB::table('report_schedules')->where('id', $row->id)->update(['active' => false, 'updated_at' => now()]);
        app(Foundation::class)->audit($request->user(), 'report.schedule_stopped', $request->user(), ['schedule_id' => $row->id]);

        return back()->with('status', 'Report schedule stopped.');
    }
}

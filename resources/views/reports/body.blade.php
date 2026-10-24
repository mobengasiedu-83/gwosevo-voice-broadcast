<link rel="stylesheet" href="{{ asset('css/reports.css') }}">
<div class="report-workspace">
<section class="panel">
<h2>Report filters</h2>
<form class="report-filters" method="get" action="{{ route($routeName) }}">
<label>Report<select name="kind">
@foreach(['campaigns'=>'Campaign audience funnel','attempts'=>'Calls and playback','billing'=>'Billing ledger','ivr'=>'IVR responses'] as $kind=>$label)
@if(auth()->user()->isClient() || auth()->user()->can($kind === 'billing' ? 'billing.view' : 'reports.view'))<option value="{{ $kind }}" @selected($filters['kind'] === $kind)>{{ $label }}</option>@endif
@endforeach
</select></label>
<label>From<input type="date" name="from" required value="{{ $filters['from'] }}"></label>
<label>Through<input type="date" name="to" required value="{{ $filters['to'] }}"></label>
<label>Timezone<select name="timezone">@foreach(timezone_identifiers_list() as $zone)<option @selected($filters['timezone']===$zone)>{{ $zone }}</option>@endforeach</select></label>
<label>Campaign ID (optional)<input type="number" name="campaign_id" min="1" value="{{ $filters['campaign_id'] ?? '' }}"></label>
<label>{{ $filters['kind']==='billing' ? 'Entry type' : 'State / outcome' }} (optional)<input name="status" pattern="[a-z_]{1,40}" maxlength="40" value="{{ $filters['status'] ?? '' }}" placeholder="{{ $filters['kind']==='billing' ? 'call_charge, refund, adjustment' : ($filters['kind']==='campaigns' ? 'completed' : 'playback_failed') }}"></label>
@if(!auth()->user()->isClient())
<label>Client account ID (optional)<input type="number" name="client_account_id" min="1" value="{{ $filters['client_account_id'] ?? '' }}"></label>
<label>Campaign owner ID (optional)<input type="number" name="owner_id" min="1" value="{{ $filters['owner_id'] ?? '' }}"></label>
@endif
<button class="button">Apply filters</button><a href="{{ route($routeName) }}">Reset</a>
</form>
<p>Choose one state, outcome or entry type, using underscores (for example <code>no_answer</code>). Date ranges include both selected dates in the chosen timezone; result timestamps are UTC.</p>
<p>Dates apply to {{ match($filters['kind']) {'campaigns'=>'campaign creation; audience counts describe frozen execution recipients, including archived runs', 'attempts'=>'attempt creation', 'billing'=>'ledger posting', 'ivr'=>'digit response time'} }}. Maximum period: 366 days.</p>
</section>
<section class="panel"><h2>Summary</h2>
<div class="report-metrics">@foreach($summary['metrics'] as $label=>$value)<article><small>{{ ucfirst(str_replace('_',' ',$label)) }}</small><strong>{{ number_format($value) }}</strong></article>@endforeach</div>
@if($filters['kind']==='attempts')<p>Answer rate: {{ $summary['metrics']['sent'] ? number_format(100 * ($summary['metrics']['answered_sent'] ?? $summary['metrics']['answered']) / $summary['metrics']['sent'], 1).'%' : 'No sent calls' }} · Playback completion among answered attempts: {{ $summary['metrics']['answered'] ? number_format(100 * ($summary['metrics']['playback_answered'] ?? $summary['metrics']['playback_completed']) / $summary['metrics']['answered'], 1).'%' : 'No answers' }}.</p>@endif
<p>An answer does not confirm playback. Playback completion records a PBX operation; it does not establish audible delivery. Campaign recipients are counted once even when retried; call reports count each attempt.</p>
<div class="report-breakdown">
@forelse($summary['groups'] as $group)
<div><strong>{{ $group->node_id ?? '' }} {{ $group->label ?? 'Unknown' }}</strong><span>{{ number_format($group->total) }}</span>
@if(isset($group->currency))<span>{{ $group->currency }} {{ \App\Services\Billing\Ledger::format((int)$group->amount_minor, (int)$group->minor_digits) }}</span>@else<progress max="{{ max(1, $summary['groups']->sum('total')) }}" value="{{ $group->total }}" aria-label="{{ $group->label ?? 'Unknown' }} count">{{ $group->total }}</progress>@endif
</div>
@empty<p>No matching activity.</p>@endforelse
</div>
@if($filters['kind']!=='billing' && (auth()->user()->isClient() || auth()->user()->can('billing.view')))<a href="{{ route($routeName, array_replace($filters, ['kind'=>'billing', 'status'=>null])) }}">View billing for these filters</a>@endif
</section>
<section class="panel"><h2>Results</h2><div class="table-scroll"><table><thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>
@forelse($rows as $row)<tr>@foreach($analytics->row($filters['kind'],$row) as $value)<td>{{ $value ?? '—' }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($headers) }}">No matching records.</td></tr>@endforelse
</tbody></table></div>{{ $rows->links(auth()->user()->isClient() ? 'clients.pagination' : 'pagination::simple-default') }}</section>
<section class="panel"><h2>Private exports</h2><p>Exports contain the filtered report rows, with a maximum of {{ number_format($maxRows) }} rows. Narrow filters if needed. CSV and XLSX downloads expire after seven days. Only you can download your files.</p>
<form method="post" action="{{ route($routeName.'.export') }}">@csrf @foreach($filters as $key=>$value)@if($value!==null)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
<label>Format<select name="format"><option value="csv">CSV</option><option value="xlsx">Excel XLSX</option></select></label><button class="button">Generate export</button></form>
<div class="table-scroll"><table><thead><tr><th>Requested UTC</th><th>Report</th><th>Rows</th><th>Status</th><th>Download</th></tr></thead><tbody>
@forelse($exports as $export)<tr><td>{{ $export->created_at->format('d M Y H:i') }}</td><td>{{ ucfirst($export->kind) }} · {{ strtoupper($export->format) }}</td><td>{{ $export->row_count }}</td><td>{{ ucfirst($export->status) }}<small>{{ $export->failure_reason }}</small></td><td>@if($export->status==='completed' && $export->expires_at->isFuture())<a href="{{ route($routeName.'.download',$export->uuid) }}">Download {{ strtoupper($export->format) }}</a>@else — @endif</td></tr>@empty<tr><td colspan="5">No exports yet.</td></tr>@endforelse
</tbody></table></div>{{ $exports->links(auth()->user()->isClient() ? 'clients.pagination' : 'pagination::simple-default') }}
<p>Refresh this page to check queued generation. Client export-ready alerts appear in Notifications; optional email/SMS alerts follow your preferences.</p>
</section>
<section class="panel"><h2>Scheduled report delivery</h2><p>Generate this report daily or weekly for the previous 7, 30 or 90 complete days. The selected status, campaign, owner and client filters are retained. Files are delivered to your private export history; client users also receive a personal in-app alert.</p>
<form class="report-filters" method="post" action="{{ route($routeName.'.schedule') }}">@csrf @foreach($filters as $key=>$value)@if($value!==null)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
<label>Frequency<select name="frequency"><option value="daily">Daily</option><option value="weekly">Weekly</option></select></label>
<label>Time in {{ $filters['timezone'] }}<input type="time" name="local_time" required value="08:00"></label>
<label>Previous complete days<select name="lookback_days">@foreach([7,30,90] as $days)<option value="{{ $days }}">{{ $days }} days</option>@endforeach</select></label>
<label>Format<select name="format"><option value="csv">CSV</option><option value="xlsx">Excel XLSX</option></select></label><button class="button">Create schedule</button></form>
@forelse($schedules as $schedule)<div class="report-schedule"><p>{{ ucfirst($schedule->kind) }} · {{ $schedule->frequency }} at {{ $schedule->local_time }} {{ $schedule->timezone }} · {{ $schedule->active ? 'Active' : 'Stopped / paused' }} · Next UTC {{ $schedule->next_run_at }}</p>@if($schedule->active)<form method="post" action="{{ route($routeName.'.stop', $schedule->id) }}">@csrf<button class="button secondary">Stop schedule</button></form>@endif</div>@empty<p>No report schedules.</p>@endforelse
</section>
</div>

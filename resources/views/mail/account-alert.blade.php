<p>Hello{{ $recipientName ? ' '.$recipientName : '' }},</p>
<p><strong>{{ $alertTitle }}</strong></p>
<p>{!! nl2br(e($messageBody)) !!}</p>
@if($actionUrl && \Illuminate\Support\Str::startsWith($actionUrl, ['http://', 'https://']))
<p><a href="{{ $actionUrl }}">Open GWO SEVO VoiceCast</a></p>
@endif
<p>You are receiving this account alert because your login is associated with a GWO SEVO VoiceCast client account. Sign in to review the current status. Do not reply to this message.</p>

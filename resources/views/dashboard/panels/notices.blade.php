@if(app(\App\Services\Clients\Tenancy::class)->membership(auth()->user()))
@if(!$gateway['healthy'])<div class="notice warning">Call status updates are delayed. Last-known results may be incomplete.</div>@endif
@if($gateway['emergency_stop'])<div class="notice warning">Calling is temporarily paused. Contact your administrator for assistance.</div>@endif
@else
@if(!$gateway['healthy'])<div class="notice warning"><span class="notice-icon">!</span><div><strong>PBX telemetry is offline or stale</strong><p>Last-known call states may be incomplete. New call admission requires a fresh gateway heartbeat and channel snapshot.</p></div></div>@endif
@if($gateway['emergency_stop'])<div class="notice danger"><strong>Emergency stop is active.</strong> Broadcast origination is blocked.</div>@endif

@endif

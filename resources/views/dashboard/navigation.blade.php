@php
    $schedulingPage = request()->routeIs('campaigns.index') && request('view') === 'scheduled';
    $reportsPage = request()->routeIs('campaigns.runs', 'broadcast.report');
    $groups = [
        'dashboard' => ['Dashboard', [
            ['Overview', 'dashboard.overview', ['dashboard.view'], request()->routeIs('dashboard.overview')],
            ['Live Calls', 'dashboard.live', ['dashboard.live'], request()->routeIs('dashboard.live', 'dashboard.call')],
            ['Agent & Queue Wallboard', 'dashboard.wallboard', ['dashboard.wallboard'], request()->routeIs('dashboard.wallboard')],
            ['System Health', 'dashboard.health', ['dashboard.health'], request()->routeIs('dashboard.health')],
        ]],
        'clients' => ['Clients', [
            ['Client accounts', 'clients.index', ['clients.manage'], request()->routeIs('clients.*')],
        ]],
        'campaigns' => ['Broadcast operations', [
            ['Campaigns', 'campaigns.index', ['campaigns.view'], request()->routeIs('campaigns.*') && !$schedulingPage && !$reportsPage],
            ['Scheduling', 'campaigns.index', ['campaigns.view'], $schedulingPage, ['view' => 'scheduled']],
            ['Dialing Operations', 'broadcast.index', ['broadcast.operate'], request()->routeIs('broadcast.*') && !$reportsPage],
            ['Reports & Analytics', 'campaigns.runs', ['reports.view'], $reportsPage],
        ]],
        'audience' => ['Audience', [
            ['Contact directory', 'contacts.index', ['contacts.view'], request()->routeIs('contacts.index', 'contacts.show', 'contacts.edit')],
            ['Contact groups', 'contacts.groups', ['contacts.view'], request()->routeIs('contacts.groups*', 'contacts.group')],
            ['Lists & imports', 'contacts.imports', ['contacts.manage'], request()->routeIs('contacts.import*')],
            ['Suppression / DNC', 'contacts.suppressions', ['contacts.view'], request()->routeIs('contacts.suppressions*')],
            ['Consent evidence', 'contacts.consents', ['contacts.view'], request()->routeIs('contacts.consents*')],
        ]],
        'voice' => ['Voice & telephony', [
            ['Audio Library', 'audio.index', ['audio.manage', 'audio.approve', 'telephony.view'], request()->routeIs('audio.*')],
            ['Voice & IVR', 'interactive.index', ['campaigns.manage', 'campaigns.approve', 'telephony.manage'], request()->routeIs('interactive.*')],
            ['Telephony', 'telephony.index', ['telephony.manage'], request()->routeIs('telephony.*')],
        ]],
        'billing' => ['Billing & finance', [
            ['Wallets', 'billing.wallets', ['billing.manage'], request()->routeIs('billing.wallets*')],
            ['Rate plans', 'billing.rates', ['billing.manage'], request()->routeIs('billing.rates*')],
            ['Finance & reports', 'billing.finance', ['billing.manage'], request()->routeIs('billing.finance*')],
            ['Payments', 'billing.payments', ['billing.manage'], request()->routeIs('billing.payments*')],
            ['Disputes', 'billing.disputes', ['billing.manage'], request()->routeIs('billing.disputes*')],
            ['Call reconciliation', 'billing.exceptions', ['telephony.manage'], request()->routeIs('billing.exceptions*')],
        ]],
        'administration' => ['Administration', [
            ['Compliance & Quality', 'workspace', ['audit.view'], request()->routeIs('workspace')],
        ]],
        'account' => ['Account', [
            ['Notifications', 'notifications.index', ['dashboard.view'], request()->routeIs('notifications.*')],
            ['Security', 'account.security', [], request()->routeIs('account.security')],
        ]],
    ];
@endphp
@foreach($groups as $key => [$label, $links])
    @php
        $visibleLinks = collect($links)->filter(fn ($link) => !$link[2] || auth()->user()->hasAnyPermission($link[2]));
        $active = $visibleLinks->contains(fn ($link) => $link[3]);
    @endphp
    @if($visibleLinks->isNotEmpty())
    <details class="nav-group admin-nav-group" data-nav-group="{{ $key }}" @if($active) open data-active="true" @endif>
        <summary class="nav-item">
            @include('dashboard.navigation-icon', ['icon' => $key])
            <span class="admin-nav-label">{{ $label }}</span>
            <svg class="admin-nav-chevron" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="m6 4 4 4-4 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </summary>
        <div class="subnav">
        @foreach($visibleLinks as $link)
            <a class="nav-item {{ $link[3] ? 'active selected' : '' }}" href="{{ route($link[1], $link[4] ?? []) }}" @if($link[3]) aria-current="page" @endif>{{ $link[0] }}</a>
        @endforeach
        </div>
    </details>
    @endif
@endforeach

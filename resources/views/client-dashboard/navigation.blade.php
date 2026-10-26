@php
    $directLinks = [
        ['dashboard.overview', 'Dashboard', 'dashboard.view'],
        ['dashboard.live', 'Live calls', 'dashboard.live'],
    ];
    $groups = [
        'campaigns' => ['Campaigns', [
            ['campaigns.index', 'My campaigns', 'campaigns.view', ['campaigns.index', 'campaigns.create', 'campaigns.show', 'campaigns.edit']],
            ['campaigns.runs', 'Reports', 'reports.view', ['campaigns.runs', 'broadcast.report']],
        ]],
        'audience' => ['Audience', [
            ['contacts.groups', 'Contact groups', 'contacts.view', ['contacts.groups', 'contacts.groups.*']],
            ['contacts.index', 'Contact directory', 'contacts.view', ['contacts.index', 'contacts.create', 'contacts.show', 'contacts.edit']],
            ['contacts.imports', 'Imports', 'contacts.manage', ['contacts.imports', 'contacts.import', 'contacts.imports.*']],
            ['contacts.consents', 'Consent evidence', 'contacts.view', ['contacts.consents', 'contacts.consents.*']],
            ['contacts.suppressions', 'Suppression / DNC', 'contacts.view', ['contacts.suppressions', 'contacts.suppressions.*']],
        ]],
        'voice' => ['Voice & recordings', [
            ['audio.index', 'Recordings', 'audio.manage', ['audio.*']],
            ['interactive.index', 'Voice & IVR', 'campaigns.manage', ['interactive.*']],
        ]],
        'billing' => ['Billing', [
            ['wallet.index', 'Calling credit', 'dashboard.view', ['wallet.index']],
            ['wallet.payments.index', 'Top-ups & receipts', 'dashboard.view', ['wallet.payments.*']],
            ['wallet.disputes.index', 'Billing disputes', 'dashboard.view', ['wallet.disputes.*']],
        ]],
        'account' => ['Account', [
            ['account.notifications', 'Notifications', 'dashboard.view', ['account.notifications', 'account.notifications.*']],
            ['account.profile', 'Account profile', null, ['account.profile']],
            ['account.security', 'Security', null, ['account.security', 'account.security.*']],
        ]],
    ];
@endphp
@foreach($directLinks as [$route, $label, $permission])
    @can($permission)
    <a class="nav-item {{ request()->routeIs($route) ? 'active' : '' }}" href="{{ route($route) }}" @if(request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
    @endcan
@endforeach
@foreach($groups as $key => [$label, $links])
    @php
        $visibleLinks = collect($links)->filter(fn ($link) => !$link[2] || auth()->user()->can($link[2]));
        $active = $visibleLinks->contains(fn ($link) => request()->routeIs(...$link[3]));
    @endphp
    @if($visibleLinks->isNotEmpty())
    <details class="client-nav-group" data-nav-group="{{ $key }}" data-nav-user="{{ auth()->id() }}" @if($active) open data-active="true" @endif>
        <summary>{{ $label }}<span class="client-nav-chevron" aria-hidden="true">›</span></summary>
        <div class="client-nav-submenu">
        @foreach($visibleLinks as [$route, $title, $permission, $patterns])
            <a class="nav-item {{ request()->routeIs(...$patterns) ? 'active' : '' }}" href="{{ route($route) }}" @if(request()->routeIs(...$patterns)) aria-current="page" @endif>{{ $title }}</a>
        @endforeach
        </div>
    </details>
    @endif
@endforeach

@can('dashboard.view')
<div class="client-sidebar-help">
    <a class="nav-item {{ request()->routeIs('account.guide') ? 'active' : '' }}" href="{{ route('account.guide') }}" @if(request()->routeIs('account.guide')) aria-current="page" @endif>User guide <span aria-hidden="true">↗</span></a>
</div>
@endcan

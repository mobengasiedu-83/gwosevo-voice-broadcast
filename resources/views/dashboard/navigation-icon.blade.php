<svg class="admin-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($icon)
@case('dashboard')<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>@break
@case('clients')<rect x="4" y="6" width="16" height="15" rx="2"/><path d="M9 6V3h6v3M4 12h16M10 12v3h4v-3"/>@break
@case('campaigns')<path d="m4 10 14-6v16L4 14v-4ZM18 9l3-1v8l-3-1M7 15l2 6h4l-2-5"/>@break
@case('audience')<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M18 14a5 5 0 0 1 3 4v3"/>@break
@case('voice')<rect x="9" y="3" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3M8 21h8"/>@break
@case('billing')<rect x="3" y="5" width="18" height="15" rx="3"/><path d="M3 10h18M7 15h4"/>@break
@case('administration')<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>@break
@default<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>
@endswitch
</svg>

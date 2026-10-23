<nav class="module-tabs" aria-label="Campaign navigation">
@foreach(['campaigns.index'=>'All campaigns','campaigns.create'=>'Create campaign','campaigns.templates'=>'Templates','campaigns.runs'=>'Campaign runs'] as $route=>$label)@if($route!=='campaigns.create'||auth()->user()->can('campaigns.manage'))<a href="{{ route($route) }}" @if(request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>@endif
@endforeach
@foreach(['scheduled'=>'Scheduled','active'=>'Active','allocation'=>'Priority & allocation'] as $viewKey=>$label)<a href="{{ route('campaigns.index',['view'=>$viewKey]) }}">{{ $label }}</a>@endforeach
</nav>

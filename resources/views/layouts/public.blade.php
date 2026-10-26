<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><script src="{{ asset('js/theme.js') }}"></script><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0d1119"><meta name="description" content="GWO SEVO brings your contacts, voice messages and campaign approvals into one workspace.">
<title>@yield('title', 'GWO SEVO · Voice broadcasting')</title>
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
<link rel="stylesheet" href="{{ asset('css/public.css') }}"><link rel="stylesheet" href="{{ asset('css/theme.css') }}"><link rel="stylesheet" href="{{ asset('css/public-theme.css') }}">
<script src="{{ asset('js/public.js') }}" defer></script>
<link rel="stylesheet" href="{{ asset('css/themes.css') }}"><link rel="stylesheet" href="{{ asset('css/identity.css') }}"><link rel="stylesheet" href="{{ asset('css/forms.css') }}"></head>
<body class="@yield('body-class')"><a class="skip-link" href="#main">Skip to content</a>@yield('content')<button type="button" class="theme-toggle public-theme-toggle" data-theme-toggle aria-label="Switch color theme" aria-pressed="false" hidden>Theme</button></body>
</html>

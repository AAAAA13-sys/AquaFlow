<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'AquaFlow')</title>
  @hasSection('meta-description')
    <meta name="description" content="@yield('meta-description')">
  @endif
  <script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
  <link rel="stylesheet" href="{{ asset('css/styles.css') }}?v={{ asset_version('css/styles.css') }}">
  @stack('styles')
  {{-- Final visual layer for every role; must stay after the portal styles. --}}
  <link rel="stylesheet" href="{{ asset('css/theme.css') }}?v={{ asset_version('css/theme.css') }}">
  <script>window.SPRITE_URL = @json(asset('icons/sprite.svg'));</script>
</head>
<body class="role-{{ auth()->user()?->role ?? 'guest' }} @yield('body-class')">
{{-- Signed-in pages put a sidebar of links before the work area; the sign-in
     page is only the form, so it has nothing to skip. Every portal <main>
     carries id="main". --}}
@auth
<a class="skip-link" href="#main">Skip to content</a>
@endauth
@yield('content')
@auth
@include('partials.profile-modal')
@endauth
@stack('scripts')
</body>
</html>

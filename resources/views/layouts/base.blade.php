<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'AquaFlow')</title>
  <script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
  <link rel="stylesheet" href="{{ asset('css/styles.css') }}?v={{ asset_version('css/styles.css') }}">
  <link rel="stylesheet" href="{{ asset('css/icons.css') }}?v={{ asset_version('css/icons.css') }}">
  <link rel="stylesheet" href="{{ asset('css/friendly.css') }}?v={{ asset_version('css/friendly.css') }}">
  <link rel="stylesheet" href="{{ asset('css/ui-system.css') }}?v={{ asset_version('css/ui-system.css') }}">
  @stack('styles')
  <script>window.SPRITE_URL = @json(asset('icons/sprite.svg'));</script>
</head>
<body class="@yield('body-class')">
@yield('content')
@stack('scripts')
</body>
</html>

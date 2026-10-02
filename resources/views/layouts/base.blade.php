<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'AquaFlow')</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
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

@extends('layouts.cashier')
@section('title', 'My Activity | ' . ($stationName ?? 'AquaFlow'))
@section('meta-description', 'What this cashier did today: sales, drawer, handovers and accepted rider cash.')
@section('topbar-title', 'My Activity')

@section('cashier-body')
  <main class="admin-main-content">
    @include('partials.activity-page')
  </main>
@endsection

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/activity.css') }}?v={{ asset_version('css/activity.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('js/data/activity.js') }}?v={{ asset_version('js/data/activity.js') }}"></script>
  <script>
    (function () {
      if (document.getElementById('activityList')) {
        refreshActivity();
        window.addEventListener('focus', refreshActivity);
        window.addEventListener('online', refreshActivity);
        setInterval(() => {
          if (!document.hidden && !document.querySelector('dialog[open]')) refreshActivity();
        }, 15000);
      }
    })();
  </script>
@endpush

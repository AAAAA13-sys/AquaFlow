@extends('layouts.base')

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/cashier.css') }}?v={{ asset_version('css/cashier.css') }}">
@endpush

@section('content')
  {{-- Only the POS terminal locks to the viewport; the queue and history pages
       keep normal page scrolling. --}}
  <div class="admin-layout @yield('shell-class') cashier-shell">
    <div id="sidebarBackdrop" class="sidebar-backdrop" onclick="toggleSidebar(false)"></div>

    @include('partials.cashier-sidebar')

    <div class="admin-main-container">
      @include('partials.portal-topbar', ['defaultTitle' => 'POS Terminal', 'clockId' => 'clock', 'clockClass' => 'header-clock'])

      @yield('cashier-body')
    </div>
  </div>

  @include('partials.scripts-base')
  @include('partials.scripts-pos')
@endsection

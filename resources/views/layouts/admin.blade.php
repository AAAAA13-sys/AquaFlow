@extends('layouts.base')

@section('body-class', '')

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/admin-pages.css') }}?v={{ asset_version('css/admin-pages.css') }}">
@endpush

@section('content')
  <div class="admin-layout">
    <div id="sidebarBackdrop" class="sidebar-backdrop" onclick="toggleSidebar(false)"></div>

    @include('partials.admin-sidebar')

    <div class="admin-main-container">
      @include('partials.portal-topbar', ['defaultTitle' => 'Station Dashboard', 'clockId' => 'adminClock', 'clockClass' => ''])

      <main class="admin-main-content">
        @yield('admin-content')
      </main>
    </div>
  </div>

  @include('partials.scripts-base')
  @include('partials.scripts-admin')
@endsection

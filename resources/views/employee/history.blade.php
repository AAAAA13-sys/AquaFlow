@extends('layouts.base')
@section('body-class', 'employee-page')
@section('title', 'My Activity | AquaFlow')
@section('topbar-title', 'My activity')

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/activity.css') }}?v={{ asset_version('css/activity.css') }}">
  <link rel="stylesheet" href="{{ asset('css/employee.css') }}?v={{ asset_version('css/employee.css') }}">
@endpush

@section('content')
<div class="admin-layout">
  <div id="sidebarBackdrop" class="sidebar-backdrop" onclick="toggleSidebar(false)"></div>

  @include('partials.employee-sidebar')

  <div class="admin-main-container">
    @include('partials.portal-topbar', [
      'defaultTitle' => 'My activity',
      'clockId' => 'employeeClock',
      'clockClass' => '',
    ])

    <main class="admin-main-content">
      <div class="emp-container" data-user-id="{{ auth()->id() }}" data-role="{{ auth()->user()->role }}" data-name="{{ auth()->user()->name }}" data-user="{{ auth()->user()->username }}">
        @include('partials.activity-page')
      </div>
    </main>
  </div>
</div>

@if(auth()->user()->role === 'refiller')
@include('partials.employee-defect-dialog')
@endif

@include('partials.scripts', ['panel' => 'employee'])
<script src="{{ asset('js/data/activity.js') }}?v={{ asset_version('js/data/activity.js') }}"></script>
<script src="{{ asset('js/employee.js') }}?v={{ asset_version('js/employee.js') }}"></script>
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
@endsection

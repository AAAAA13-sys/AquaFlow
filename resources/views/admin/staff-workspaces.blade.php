@extends('layouts.admin')

@section('title', 'Staff Workspaces | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Staff Workspaces')

@section('admin-content')
  @php
    // The role this account is acting under on the staff tools. A manager or an
    // owner can work the queue as a rider or a refiller; the actor recorded on
    // the order is still the signed-in account.
    $mode = in_array(request()->query('mode'), ['rider', 'refiller'], true) ? request()->query('mode') : 'rider';
    $acting = $mode === 'rider' ? 'Delivery Rider' : 'Refiller';
  @endphp

  <section id="s-staff" class="admin-page">
    @include('partials.admin-page-header', [
      'eyebrow' => 'STAFF INTERFACES',
      'heading' => 'Staff workspaces',
      'description' => 'The same queue your staff work on, so you can cover a shift or check what the floor is doing. Every action is recorded under your own name.',
    ])

    <div class="card staff-mode-bar">
      <div class="staff-mode-switch" role="group" aria-label="Staff role to work as">
        <a href="{{ url('/admin/staff-workspaces?mode=refiller') }}"
           class="seg-btn {{ $mode === 'refiller' ? 'active' : '' }}"
           @if ($mode === 'refiller') aria-current="true" @endif>
          <svg class="icon" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#i-package"></use></svg>
          <span>Refiller</span>
        </a>
        <a href="{{ url('/admin/staff-workspaces?mode=rider') }}"
           class="seg-btn {{ $mode === 'rider' ? 'active' : '' }}"
           @if ($mode === 'rider') aria-current="true" @endif>
          <svg class="icon" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#i-truck"></use></svg>
          <span>Delivery Rider</span>
        </a>
      </div>
      <p class="cell-sub">
        Working as <strong>{{ $acting }}</strong>. Orders you confirm here are recorded against your account,
        with the role you acted under, so the trail shows the owner stepped in.
      </p>
      <a class="btn btn-secondary" href="{{ url('/cashier') }}">
        <svg class="icon" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#i-terminal"></use></svg>
        <span>Open the POS Terminal</span>
      </a>
      <a class="btn btn-ghost" href="{{ url('/cashier/activity') }}">
        <svg class="icon" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#i-receipt"></use></svg>
        <span>My activity</span>
      </a>
    </div>

    <div class="emp-container"
         data-user-id="{{ auth()->id() }}"
         data-role="{{ $mode }}"
         data-mode="{{ $mode }}"
         data-name="{{ auth()->user()->name }}"
         data-user="{{ auth()->user()->username }}">
      <div class="emp-status-bar">
        <div class="emp-pulse-wrapper">
          <span id="pulseDot" class="emp-pulse-dot"></span>
          <span id="connection" role="status">Updating...</span>
        </div>
        <span id="orderCount" class="emp-count-pill">0</span>
      </div>

      <div id="error" class="emp-alert hidden" role="alert"></div>

      <aside id="employeeRecovery" class="emp-alert" hidden role="status">
        <p id="employeeRecoveryText"></p>
        <button type="button" id="retryEmployeeAction" class="btn btn-primary" onclick="retryEmployeeAction()">Retry saved action</button>
      </aside>

      <p id="employeeResult" role="status"></p>

      <section aria-labelledby="staffQueueTitle">
        <h2 id="staffQueueTitle" class="emp-section-title">
          {{ $mode === 'rider' ? 'Delivery queue' : 'Refill queue' }}
        </h2>
        <div id="jobs">
          <div class="emp-empty-state">Loading assigned orders...</div>
        </div>
      </section>
    </div>
  </section>
@endsection

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/employee.css') }}?v={{ asset_version('css/employee.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('js/employee-delivery.js') }}?v={{ asset_version('js/employee-delivery.js') }}"></script>
  <script src="{{ asset('js/employee.js') }}?v={{ asset_version('js/employee.js') }}"></script>
@endpush

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Staff Workspaces'])
@endcomponent

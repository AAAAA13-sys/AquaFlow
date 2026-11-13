@extends('layouts.base')
@section('body-class', 'employee-page')
@section('title', (auth()->user()?->role === 'rider' ? 'Delivery Queue' : 'Refill Queue') . ' | AquaFlow')

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/employee.css') }}?v={{ asset_version('css/employee.css') }}">
@endpush

@section('content')
<div class="admin-layout">
  <div id="sidebarBackdrop" class="sidebar-backdrop" onclick="toggleSidebar(false)"></div>

  @include('partials.employee-sidebar')

  <div class="admin-main-container">
    @include('partials.portal-topbar', [
      'defaultTitle' => auth()->user()->role === 'rider' ? 'Delivery Queue' : 'Refill Queue',
      'defaultGroup' => auth()->user()->roleLabel(),
    ])

    <main id="main" tabindex="-1" class="admin-main-content">
      <div class="emp-container" data-user-id="{{ auth()->id() }}" data-role="{{ auth()->user()->role }}" data-name="{{ auth()->user()->name }}" data-user="{{ auth()->user()->username }}">
        <div class="emp-queue-toolbar" role="group" aria-label="Queue status and actions">
          <div class="emp-queue-header-left">
            <h2 class="emp-section-title">
              <span id="queueSectionTitle">{{ auth()->user()->role === 'rider' ? 'Ready to deliver' : 'To prepare' }}</span>
              <span id="orderCount" class="emp-count-pill">0</span>
            </h2>
            <p class="emp-queue-desc">{{ auth()->user()->role === 'rider'
              ? 'Oldest ready order first. Confirm delivery only after the customer has the items.'
              : 'Check every item and quantity, then mark the order ready.' }}</p>
          </div>
          <div class="emp-queue-header-actions">
            <div class="emp-pulse-wrapper">
              <span id="pulseDot" class="emp-pulse-dot"></span>
              <span id="connection" role="status">Updating...</span>
            </div>
            <button type="button" class="emp-refresh-btn emp-refresh-main" onclick="refreshEmployee()">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
              Refresh
            </button>
            @if(auth()->user()->role === 'refiller')
            <button type="button" class="emp-refresh-btn emp-refresh-btn-warn" onclick="openEmployeeDefectDialog()" aria-label="Report Defect or Damage">
              <svg class="icon" width="14" height="14" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#i-alert"></use></svg>
              Report Defect
            </button>
            @endif
          </div>
        </div>

        <div id="error" class="emp-alert hidden" role="alert"></div>

        <aside id="employeeRecovery" class="emp-alert emp-recovery" hidden role="status">
          <p id="employeeRecoveryText" class="emp-recovery-text"></p>
          <button type="button" id="retryEmployeeAction" class="btn btn-primary emp-recovery-btn" onclick="retryEmployeeAction()">Retry saved action</button>
        </aside>

        <p id="employeeResult" role="status"></p>

        <section aria-labelledby="queueSectionTitle">
          <div id="jobs">
            <div class="emp-empty-state" role="status">Loading assigned orders...</div>
          </div>
        </section>
      </div>
    </main>
  </div>
</div>

@if(auth()->user()->role === 'refiller')
@include('partials.employee-defect-dialog')
@endif

@include('partials.scripts', ['panel' => 'employee'])
<script src="{{ asset('js/employee-delivery.js') }}?v={{ asset_version('js/employee-delivery.js') }}"></script>
<script src="{{ asset('js/employee.js') }}?v={{ asset_version('js/employee.js') }}"></script>
@endsection

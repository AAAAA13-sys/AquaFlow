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
      'clockId' => 'employeeClock',
      'clockClass' => '',
    ])

    <main class="admin-main-content">
      <div class="emp-container" data-user-id="{{ auth()->id() }}" data-role="{{ auth()->user()->role }}" data-name="{{ auth()->user()->name }}" data-user="{{ auth()->user()->username }}">
        <div class="emp-queue-toolbar">
          <div class="emp-queue-header-left">
            <h1 class="emp-section-title">
              <span id="queueSectionTitle">{{ auth()->user()->role === 'rider' ? 'Delivery Queue' : 'Refill Queue' }}</span>
              <span id="orderCount" class="emp-count-pill">0</span>
            </h1>
            <p class="emp-queue-desc">{{ auth()->user()->role === 'rider'
              ? 'Start the oldest ready order once the cashier confirms handover, then confirm delivery after the customer receives the items.'
              : 'Check the receipt, product types and quantities. Mark ready only after every item is prepared.' }}</p>
          </div>
          <div class="emp-queue-header-actions">
            <div class="emp-pulse-wrapper">
              <span id="pulseDot" class="emp-pulse-dot"></span>
              <span id="connection" role="status">Updating...</span>
            </div>
            <button type="button" class="emp-refresh-btn" onclick="refreshEmployee()">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
              Refresh
            </button>
            @if(auth()->user()->role === 'refiller')
            <button type="button" class="emp-refresh-btn emp-refresh-btn-warn" onclick="openEmployeeDefectDialog()" aria-label="Report Defect or Damage">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
              Report Defect
            </button>
            @endif
          </div>
        </div>

        <div id="error" class="emp-alert hidden" role="alert"></div>

        <aside id="employeeRecovery" class="emp-alert" hidden role="status">
          <p id="employeeRecoveryText"></p>
          <button type="button" id="retryEmployeeAction" class="btn btn-primary" onclick="retryEmployeeAction()">Retry saved action</button>
        </aside>

        <p id="employeeResult" role="status"></p>

        <section aria-labelledby="queueSectionTitle">
          <div id="jobs">
            <div class="emp-empty-state">Loading assigned orders...</div>
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

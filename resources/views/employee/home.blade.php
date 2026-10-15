@extends('layouts.base')
@section('body-class', 'employee-page')
@section('title', 'My Work | AquaFlow')

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/employee.css') }}?v={{ asset_version('css/employee.css') }}">
@endpush

@section('content')
<main class="emp-container" data-user-id="{{ auth()->id() }}" data-role="{{ auth()->user()->role }}" data-name="{{ auth()->user()->name }}" data-user="{{ auth()->user()->username }}">
  <!-- Top App Bar -->
  <header class="emp-header">
    <div class="emp-user-info" onclick="openProfileModal()" style="cursor:pointer;" title="Click to view profile and change password" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' ')openProfileModal()">
      @php
        $names = explode(' ', trim(auth()->user()->name));
        $initials = strtoupper(substr($names[0] ?? 'E', 0, 1) . substr($names[count($names) - 1] ?? '', 0, 1));
        $roleName = auth()->user()->role === 'rider' ? 'Delivery Rider' : (auth()->user()->role === 'refiller' ? 'Refiller' : ucfirst(auth()->user()->role));
      @endphp
      <div class="emp-avatar" id="empAvatar">{{ $initials }}</div>
      <div class="emp-user-text">
        <h1 id="empHeaderName">{{ auth()->user()->name }}</h1>
        <span class="emp-role-tag">{{ $roleName }}</span>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <button type="button" class="emp-profile-btn" onclick="openProfileModal()">Settings</button>
      <button type="button" class="emp-signout-btn" onclick="confirmEmployeeLogout()">Sign out</button>
    </div>
  </header>

  <!-- Status Bar (No refresh here) -->
  <div class="emp-status-bar">
    <div class="emp-pulse-wrapper">
      <span id="pulseDot" class="emp-pulse-dot"></span>
      <span id="connection" role="status">Updating...</span>
    </div>
  </div>

  <!-- Error alert banner -->
  <div id="error" class="emp-alert hidden" role="alert"></div>

  <p class="emp-order-meta">{{ auth()->user()->role === 'rider' ? 'Ready deliveries are available in the shared rider queue. Start the oldest ready order after the cashier confirms handover. Confirm delivery only after the customer receives the items. Submit a photo, the buyer code and cash collected. Buyer debt is reduced only when the cashier accepts your remittance.' : 'Check the receipt, product types and quantities. Mark ready only after all items are prepared; ready deliveries appear in the shared rider queue.' }}</p>
  <aside id="employeeRecovery" class="emp-alert" hidden role="status">
    <p id="employeeRecoveryText"></p>
    <button type="button" id="retryEmployeeAction" class="btn btn-primary" onclick="retryEmployeeAction()">Retry saved action</button>
  </aside>
  <p id="employeeResult" role="status"></p>

  <!-- Orders Section (Refresh button placed here) -->
  <section>
    <div class="emp-section-head">
      <h2 class="emp-section-title">
        <span id="queueSectionTitle">{{ auth()->user()->role === 'rider' ? 'Delivery Queue' : (auth()->user()->role === 'refiller' ? 'Refill Queue' : 'My Orders') }}</span>
        <span id="orderCount" class="emp-count-pill">0</span>
      </h2>
      <button type="button" class="emp-refresh-btn" onclick="refreshEmployee()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Refresh
      </button>
    </div>
    <div id="jobs">
      <div class="emp-empty-state">Loading assigned orders...</div>
    </div>
  </section>
</main>

@include('partials.scripts-base')
<script src="{{ asset('js/employee-delivery.js') }}?v={{ asset_version('js/employee-delivery.js') }}"></script>
<script src="{{ asset('js/employee.js') }}?v={{ asset_version('js/employee.js') }}"></script>
@endsection

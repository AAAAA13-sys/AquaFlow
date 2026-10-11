@extends('layouts.base')
@section('body-class', 'employee-page')
@section('title', 'My Work | AquaFlow')

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/employee.css') }}?v={{ asset_version('css/employee.css') }}">
@endpush

@section('content')
<main class="emp-container" data-role="{{ auth()->user()->role }}" data-name="{{ auth()->user()->name }}" data-user="{{ auth()->user()->username }}">
  <!-- Top App Bar -->
  <header class="emp-header">
    <div class="emp-user-info">
      @php
        $names = explode(' ', trim(auth()->user()->name));
        $initials = strtoupper(substr($names[0] ?? 'E', 0, 1) . substr($names[count($names) - 1] ?? '', 0, 1));
        $roleName = auth()->user()->role === 'rider' ? 'Delivery Rider' : (auth()->user()->role === 'refiller' ? 'Refiller' : ucfirst(auth()->user()->role));
      @endphp
      <div class="emp-avatar">{{ $initials }}</div>
      <div class="emp-user-text">
        <h1>{{ auth()->user()->name }}</h1>
        <span class="emp-role-tag">{{ $roleName }}</span>
      </div>
    </div>
    <button type="button" class="emp-signout-btn" onclick="logout()">Sign out</button>
  </header>

  <!-- Status Bar (No refresh here) -->
  <div class="emp-status-bar">
    <div class="emp-pulse-wrapper">
      <span id="pulseDot" class="emp-pulse-dot"></span>
      <span id="connection">Updating...</span>
    </div>
  </div>

  <!-- Error alert banner -->
  <div id="error" class="emp-alert hidden" role="alert"></div>

  <!-- Shift Notice Banner -->
  <div id="shiftNotice" class="emp-alert hidden" style="background:#EFF6FF;border-color:#BFDBFE;color:#1D4ED8;"></div>

  <!-- Attendance Card -->
  <section class="emp-card">
    <div class="emp-card-header">
      <h2 class="emp-card-title">Daily Attendance</h2>
      <div id="attendanceBadgeContainer">
        <span id="attendanceBadge" class="emp-badge emp-badge-neutral">Loading...</span>
      </div>
    </div>

    <div class="emp-stat-grid">
      <div class="emp-stat-box">
        <div class="emp-stat-label">Time In</div>
        <div id="timeInDisplay" class="emp-stat-value">--:--</div>
      </div>
      <div class="emp-stat-box">
        <div class="emp-stat-label">Time Out</div>
        <div id="timeOutDisplay" class="emp-stat-value">--:--</div>
      </div>
    </div>

    <div class="emp-btn-row">
      <button type="button" id="btnTimeIn" class="emp-punch-btn emp-punch-in" onclick="punchWithValidation('time-in')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        <span id="btnTimeInText">Time in</span>
      </button>
      <button type="button" id="btnTimeOut" class="emp-punch-btn emp-punch-out" onclick="punchWithValidation('time-out')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span id="btnTimeOutText">Time out</span>
      </button>
    </div>
  </section>

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
<script src="{{ asset('js/employee.js') }}?v={{ asset_version('js/employee.js') }}"></script>
@endsection

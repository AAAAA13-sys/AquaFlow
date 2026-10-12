@extends('layouts.cashier')
@section('title', 'Attendance | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Attendance')

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/employee.css') }}?v={{ asset_version('css/employee.css') }}">
  <style>
    .cashier-attendance-wrapper {
      max-width: 580px;
      margin: 1.5rem auto;
      padding: 0 1rem;
    }
    .cashier-attendance-card {
      background: var(--surface-card, #FFFFFF);
      border: 1px solid var(--border-line, #D3DAE5);
      border-radius: 16px;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05), 0 8px 24px rgba(15, 23, 42, 0.04);
      padding: 1.5rem;
      margin-bottom: 1.5rem;
    }
    .cashier-attendance-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1.25rem;
      padding-bottom: 0.85rem;
      border-bottom: 1px solid var(--border-line, #E2E8F0);
    }
    .cashier-attendance-title {
      font-size: 1.125rem;
      font-weight: 700;
      color: var(--text-main, #1E293B);
      margin: 0;
    }
    .cashier-stat-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }
    .cashier-stat-box {
      background: #F8FAFC;
      border: 1px solid var(--border-line, #E2E8F0);
      border-radius: 12px;
      padding: 1rem;
      text-align: center;
    }
    .cashier-stat-label {
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #64748B;
      margin-bottom: 0.35rem;
    }
    .cashier-stat-value {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--text-main, #1E293B);
      font-variant-numeric: tabular-nums;
    }
    .cashier-punch-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }
    .cashier-punch-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      height: 48px;
      border-radius: 12px;
      font-size: 0.95rem;
      font-weight: 700;
      cursor: pointer;
      border: 0;
      transition: all 0.15s ease;
    }
    .cashier-punch-in {
      background: #16A34A;
      color: #FFFFFF;
    }
    .cashier-punch-in:hover:not(:disabled) {
      background: #15803D;
    }
    .cashier-punch-out {
      background: #DC2626;
      color: #FFFFFF;
    }
    .cashier-punch-out:hover:not(:disabled) {
      background: #B91C1C;
    }
    .cashier-punch-btn:disabled {
      opacity: 0.45;
      cursor: not-allowed;
    }
    .cashier-guidelines-card {
      background: #FFFFFF;
      border: 1px solid var(--border-line, #E2E8F0);
      border-radius: 14px;
      padding: 1.25rem;
      font-size: 0.85rem;
      color: #475569;
    }
    .cashier-guidelines-title {
      font-size: 0.875rem;
      font-weight: 700;
      color: #1E293B;
      margin: 0 0 0.5rem;
    }
    .cashier-guidelines-list {
      margin: 0;
      padding-left: 1.25rem;
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
    }
  </style>
@endpush

@section('cashier-body')
<main class="admin-main-content cashier-page">
  <div class="cashier-attendance-wrapper">
    <!-- Error banner -->
    <div id="attendanceError" class="emp-alert hidden" role="alert" style="margin-bottom:1rem;background:#FEE2E2;border:1px solid #FECACA;color:#B91C1C;padding:12px 14px;border-radius:10px;font-size:13px;font-weight:600;"></div>

    <!-- Shift Notice Banner -->
    <div id="attendanceNotice" class="emp-alert hidden" style="margin-bottom:1rem;background:#EFF6FF;border:1px solid #BFDBFE;color:#1D4ED8;padding:12px 14px;border-radius:10px;font-size:13px;font-weight:600;"></div>

    <!-- Attendance Card -->
    <section class="cashier-attendance-card">
      <div class="cashier-attendance-header">
        <div>
          <h2 class="cashier-attendance-title">Daily Attendance</h2>
          <span style="font-size:0.8rem;color:#64748B;">Staff Attendance · <b>{{ auth()->user()->name }}</b></span>
        </div>
        <div id="attendanceBadgeContainer">
          <span id="attendanceBadge" class="emp-badge emp-badge-neutral">Loading...</span>
        </div>
      </div>

      <div class="cashier-stat-grid">
        <div class="cashier-stat-box">
          <div class="cashier-stat-label">Time In</div>
          <div id="timeInDisplay" class="cashier-stat-value">--:--</div>
        </div>
        <div class="cashier-stat-box">
          <div class="cashier-stat-label">Time Out</div>
          <div id="timeOutDisplay" class="cashier-stat-value">--:--</div>
        </div>
      </div>

      <div class="cashier-punch-row">
        <button type="button" id="btnTimeIn" class="cashier-punch-btn cashier-punch-in" onclick="punchCashierAttendance('time-in')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
          <span id="btnTimeInText">Time in</span>
        </button>
        <button type="button" id="btnTimeOut" class="cashier-punch-btn cashier-punch-out" onclick="punchCashierAttendance('time-out')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span id="btnTimeOutText">Time out</span>
        </button>
      </div>
    </section>

    <!-- Shift Guidelines -->
    <section class="cashier-guidelines-card">
      <h3 class="cashier-guidelines-title">Attendance Policy & Station Rules</h3>
      <ul class="cashier-guidelines-list">
        <li>Store opening is at <b>8:00 AM</b>. Clock in on or before 8:00 AM for On Time status.</li>
        <li>Arrivals after 8:00 AM require a brief reason for late arrival.</li>
        <li>Standard work shift is <b>9 hours</b> including scheduled breaks and meal period.</li>
        <li>Leaving early requires an explanation for administrative record keeping.</li>
      </ul>
    </section>
  </div>
</main>

<script src="{{ asset('js/cashier-attendance.js') }}?v={{ asset_version('js/cashier-attendance.js') }}"></script>
@endsection
@component('partials.page-startup',['portal'=>'cashier'])
await loadCashierAttendance();
startPageRefresh(loadCashierAttendance);
@endcomponent

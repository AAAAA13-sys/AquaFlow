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
        $names = preg_split('/\s+/u', trim(preg_replace('/\s*\([^)]*\)\s*/u', ' ', auth()->user()->name)));
        $initials = mb_strtoupper(mb_substr($names[0] ?? 'E', 0, 1) . (count($names) > 1 ? mb_substr($names[count($names) - 1], 0, 1) : ''));
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
      <button type="button" class="emp-refresh-btn" style="background:#fef2c2;color:#92400e;border-color:#fde68a;" onclick="openEmployeeDefectDialog()" title="Report Defect or Damage" aria-label="Report Defect">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
        Report Defect
      </button>
    </div>
    <div id="jobs">
      <div class="emp-empty-state">Loading work queue...</div>
    </div>
  </section>

  <!-- Report Defect Dialog -->
  <dialog id="empDefectDialog" class="employee-issue-dialog" aria-labelledby="empDefectTitle" style="width:420px;max-width:calc(100vw - 24px);border-radius:14px;padding:0;box-shadow:0 12px 28px rgba(0,0,0,0.18);">
    <div class="receipt-card" style="padding:22px;background:#fff;">
      <h3 id="empDefectTitle" style="margin:0 0 4px;font-size:16px;color:#0f172a;">Report Defect or Damage</h3>
      <p style="margin:0 0 16px;font-size:12px;color:#64748b;">A manager will review and write the units off stock.</p>
      <div style="display:flex;flex-direction:column;gap:12px;">
        <div>
          <label class="form-label" for="empDefectItem">Item</label>
          <select id="empDefectItem" class="form-input" style="width:100%;box-sizing:border-box;"></select>
        </div>
        <div>
          <label class="form-label" for="empDefectQty">Quantity</label>
          <input id="empDefectQty" type="number" min="1" class="form-input" style="width:100%;box-sizing:border-box;" placeholder="How many are damaged?">
        </div>
        <div>
          <label class="form-label" for="empDefectReason">Reason</label>
          <input id="empDefectReason" type="text" maxlength="255" class="form-input" style="width:100%;box-sizing:border-box;" placeholder="e.g. Leaking jug, torn seal, cracked cap">
        </div>
      </div>
      <p id="empDefectError" class="auth-error hidden" role="alert" style="margin:12px 0 0;font-size:13px;"></p>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
        <button type="button" class="btn btn-ghost" onclick="closeEmployeeDefectDialog()">Cancel</button>
        <button type="button" id="empDefectSubmitBtn" class="btn btn-primary" onclick="submitEmployeeDefect()">Submit report</button>
      </div>
    </div>
  </dialog>
</main>

@include('partials.scripts', ['panel' => 'employee'])
<script src="{{ asset('js/employee-delivery.js') }}?v={{ asset_version('js/employee-delivery.js') }}"></script>
<script src="{{ asset('js/employee.js') }}?v={{ asset_version('js/employee.js') }}"></script>
@endsection

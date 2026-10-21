@extends('layouts.cashier')
@section('title', 'Orders in Progress | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Orders in Progress')
@section('cashier-body')
<main class="admin-main-content cashier-page cashier-orders-page">
 <header class="cashier-page-header">
  <div>
    <p class="cashier-eyebrow">ORDER HANDOVER</p>
    <h2>Orders in progress</h2>
    <p>Confirm walk-in pickup after preparation. Riders confirm deliveries; accept their handover and remittances below.</p>
  </div>
  <div>
    <button type="button" id="queueReportDefectBtn" onclick="openDefectReportDialog()" class="btn btn-secondary btn-sm" title="Report Defect or Damage" aria-label="Report Defect or Damage" style="display:inline-flex;align-items:center;gap:6px;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
        <circle cx="12" cy="12" r="3"/>
      </svg>
      <span>Report Defect</span>
    </button>
  </div>
 </header>
 <div class="card queue-workspace">
 <div class="queue-toolbar">
 <div class="filter-toolbar" role="group" aria-label="Order status"><button id="activeOrdersTab" class="btn btn-primary" onclick="selectQueueStatus('active')" aria-pressed="true">Active orders</button><button id="deliveredOrdersTab" class="btn btn-ghost" onclick="selectQueueStatus('delivered')" aria-pressed="false">Delivered</button></div>
 <div class="queue-search"><label class="form-label" for="queueSearch">Search orders</label><input id="queueSearch" class="form-input" type="search" maxlength="120" placeholder="Receipt or customer name" oninput="searchQueue()"></div>
 </div>
 <div class="queue-section-heading"><div><h3 id="queueHeading" class="panel-heading">Active orders</h3><p class="cell-sub">Updates automatically</p></div><span id="queueCount" class="order-receipt-tag" role="status">Loading…</span></div>
 <div id="queue" class="orders-queue-list queue-list-vertical"></div><div id="queuePages" class="filter-toolbar"></div>
 </div>
@include('partials.fulfillment')
@include('partials.defect-dialog')
</main>
@push('scripts')
<script src="{{ asset('js/pos/stocks.js') }}?v={{ asset_version('js/pos/stocks.js') }}"></script>
@endpush
@endsection
@component('partials.page-startup',['portal'=>'cashier'])
await refreshQueuePage();
startPageRefresh(refreshQueuePage);
@endcomponent

@extends('layouts.cashier')
@section('title', 'Orders in Progress | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Orders in Progress')
@section('page-actions')
<div class="page-head__actions">
    <button type="button" id="queueReportDefectBtn" onclick="openDefectReportDialog()" class="btn btn-secondary btn-icon-label" title="Report Defect or Damage" aria-label="Report Defect or Damage">
      <svg class="icon" width="15" height="15" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#i-alert"></use></svg>
      <span>Report Defect</span>
    </button>
  </div>
@endsection

@section('cashier-body')
<main id="main" tabindex="-1" class="admin-main-content cashier-page cashier-orders-page">
 <header class="page-head">
  <div class="page-head__text">
    <h2 class="page-head__title">Orders in progress</h2>
    <p class="page-head__purpose">Confirm walk-in pickup after preparation. Riders confirm deliveries; accept their handover and remittances below.</p>
  </div>

 </header>
 <div class="card queue-workspace">
 <div class="queue-toolbar">
 <div class="filter-toolbar" role="group" aria-label="Order status"><button id="activeOrdersTab" class="btn btn-primary" onclick="selectQueueStatus('active')" aria-pressed="true">Active orders</button><button id="deliveredOrdersTab" class="btn btn-ghost" onclick="selectQueueStatus('delivered')" aria-pressed="false">Delivered</button></div>
 <div class="queue-search"><label class="form-label" for="queueSearch">Search orders</label><input id="queueSearch" class="form-input" type="search" maxlength="120" placeholder="Receipt or customer name" oninput="searchQueue()"></div>
 </div>
 <div class="queue-section-heading"><div><h3 id="queueHeading" class="panel-heading">Active orders</h3><p class="cell-sub">Updates automatically</p></div><span id="queueCount" class="cashier-count" role="status">Loading…</span></div>
 <div id="queue" class="orders-queue-list queue-list-vertical"></div><div id="queuePages" class="filter-toolbar queue-pages"></div>
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

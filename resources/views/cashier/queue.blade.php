@extends('layouts.cashier')
@section('title', 'Orders in Progress | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Orders in Progress')
@section('cashier-body')
<main class="admin-main-content cashier-page cashier-orders-page">
 <header class="cashier-page-header"><div><p class="cashier-eyebrow">ORDER HANDOVER</p><h2>Orders in progress</h2><p>Track orders in progress and confirm customer pickup or delivery completion.</p></div></header>
 <div class="card queue-workspace">
 <div class="queue-toolbar">
 <div class="filter-toolbar" role="group" aria-label="Order status"><button id="activeOrdersTab" class="btn btn-primary" onclick="selectQueueStatus('active')" aria-pressed="true">Active orders</button><button id="deliveredOrdersTab" class="btn btn-ghost" onclick="selectQueueStatus('delivered')" aria-pressed="false">Delivered</button></div>
 <div class="queue-search"><label class="form-label" for="queueSearch">Search orders</label><input id="queueSearch" class="form-input" type="search" maxlength="120" placeholder="Receipt or customer name" oninput="searchQueue()"></div>
 </div>
 <div class="queue-section-heading"><div><h3 id="queueHeading" class="panel-heading">Active orders</h3><p class="cell-sub">Updates automatically</p></div><span id="queueCount" class="order-receipt-tag" role="status">Loading…</span></div>
 <div id="queue" class="orders-queue-list queue-list-vertical"></div><div id="queuePages" class="filter-toolbar"></div>
 </div>
</main>
@endsection
@component('partials.page-startup',['portal'=>'cashier'])
await refreshQueuePage();
startPageRefresh(refreshQueuePage);
@endcomponent

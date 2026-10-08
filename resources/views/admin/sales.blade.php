@extends('layouts.admin')

@section('title', 'Sales & POS Logs | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Sales History')

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/sales.css') }}?v={{ asset_version('css/sales.css') }}">
@endpush

@section('admin-content')
  <section id="s-sales">
    <header class="sales-header">
      <div><p class="sales-eyebrow">TRANSACTION RECORDS</p><h2>Sales history</h2><p>Review sales, account charges, and payments recorded by your team.</p></div>
      <button type="button" onclick="exportSalesCSV()" class="btn btn-primary">Export CSV &darr;</button>
    </header>
    <div id="salesSum" class="sales-summary-grid" aria-label="Summary of filtered transactions"></div>
    <section class="card sales-filters no-print" aria-label="Filter transactions">
      <div class="sales-filter-heading"><h3>Filter transactions</h3><button type="button" class="btn btn-tertiary btn-sm" onclick="resetSalesFilters()">Reset filters</button></div>
      <div class="sales-filter-controls">
      <div class="filter-group">
        <label class="form-label" for="fFrom">From ({{ config('app.timezone') }})</label>
        <input id="fFrom" type="datetime-local" step="60" value="{{ now()->startOfDay()->format('Y-m-d\TH:i') }}" data-default="{{ now()->startOfDay()->format('Y-m-d\TH:i') }}" onchange="renderSalesTable()">
      </div>
      <div class="filter-group">
        <label class="form-label" for="fTo">Through</label>
        <input id="fTo" type="datetime-local" step="60" value="{{ now()->endOfDay()->format('Y-m-d\TH:i') }}" data-default="{{ now()->endOfDay()->format('Y-m-d\TH:i') }}" onchange="renderSalesTable()">
      </div>
      <div class="filter-group">
        <label class="form-label" for="fChannel">Order type</label>
        <select id="fChannel" onchange="renderSalesTable()">
          <option>All</option>
          <option>Walk-in</option>
          <option>Delivery</option>
          <option>Debt Payment</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="form-label" for="fPay">Payment</label>
        <select id="fPay" onchange="renderSalesTable()">
          <option>All</option>
          <option>Cash</option>
          <option>Account</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="form-label" for="fCashier">Cashier</label>
        <select id="fCashier" onchange="renderSalesTable()">
          <option value="">All cashiers</option>
        </select>
      </div>
      @include('partials.table-filters', ['target' => 'salesBody', 'label' => 'sales', 'refresh' => "renderSalesTable()", 'embedded' => true])
      </div>
    </section>

    <section class="card sales-ledger" aria-labelledby="salesLedgerTitle">
      <div class="sales-ledger-heading"><h3 id="salesLedgerTitle">Transactions</h3><p id="salesResults" role="status">Loading transactions…</p></div>
      <div class="data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>Receipt</th>
            <th>Date &amp; time</th>
            <th>Customer</th>
            <th>Order type</th>
            <th>Gallons</th>
            <th class="num">Total</th>
            <th>Payment</th>
            <th>Cashier</th>
          </tr>
        </thead>
        <tbody id="salesBody"></tbody>
      </table>
      </div>
      <nav id="salesPagination" class="sales-pagination" aria-label="Transaction pages"></nav>
    </section>
    <p class="auth-footer-text text-center">Every transaction is recorded with the active cashier and completion timestamp.</p>
  </section>
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Sales History'])
renderSalesTable();
startPageRefresh(renderSalesTable);
@endcomponent

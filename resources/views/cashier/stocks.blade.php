@extends('layouts.cashier')

@section('title', 'Stock Availability | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Stock Availability')

@section('page-actions')
<div class="page-head__actions">
        <button type="button" id="refreshStocksBtn" onclick="loadCashierStocks()" class="btn btn-secondary btn-icon-label">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
          </svg>
          <span>Refresh</span>
        </button>
        <button type="button" onclick="openDefectReportDialog()" class="btn btn-secondary btn-icon-label">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 9v4M12 17h.01"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
          </svg>
          <span>Report Defect</span>
        </button>
      </div>
@endsection

@section('cashier-body')
  <main id="main" tabindex="-1" class="admin-main-content cashier-page cashier-stocks-page">
    <header class="page-head">
      <div class="page-head__text">
        <h2 class="page-head__title">Stock availability</h2>
        <p class="page-head__purpose">Read-only stock levels. Ask your manager to restock low supplies. Final order availability is checked at the POS.</p>
      </div>

    </header>

    <section class="card cashier-ledger cashier-stock-ledger" aria-labelledby="stockLedgerTitle">
      <div class="cashier-ledger-heading">
        <h3 id="stockLedgerTitle" class="panel-heading">Supplies &amp; inventory</h3>
        <span id="cStockCountBadge" class="cashier-count" role="status">Loading…</span>
      </div>

      {{-- Filters --}}
      <div class="filter-bar no-print" role="group" aria-label="Filter stock">
        <div class="filter-group">
          <label for="cStockCatFilter">Category</label>
          <select id="cStockCatFilter" class="form-input" onchange="renderCashierStocksTable()">
            <option value="">All Categories</option>
            <option value="Consumable">Consumable</option>
            <option value="Asset">Asset</option>
            <option value="Filtration">Filtration</option>
            <option value="Cleaning">Cleaning</option>
          </select>
        </div>
        <div class="filter-group">
          <label for="cStockSearchInput">Search</label>
          <input id="cStockSearchInput" type="search" class="form-input" placeholder="Search by item name..." oninput="renderCashierStocksTable()">
        </div>
        <div class="filter-group">
          <label for="cStockSortSelect">Sort</label>
          <select id="cStockSortSelect" class="form-input" onchange="renderCashierStocksTable()">
            <option value="lowest">Stock: Lowest first</option>
            <option value="highest">Stock: Highest first</option>
            <option value="name">Name (A to Z)</option>
          </select>
        </div>
      </div>

      <div class="data-table-wrapper" tabindex="0" role="region" aria-label="Stock table">
        <table class="clean">
          <caption class="visually-hidden">Live inventory list for station cashiers.</caption>
          <thead>
            <tr>
              <th>Item Name</th>
              <th>Category</th>
              <th class="num">In Stock</th>
              <th class="text-center">Status</th>
            </tr>
          </thead>
          <tbody id="cStockBody">
            <tr>
              <td colspan="4" class="empty-cell"><span class="skeleton skeleton--block" aria-hidden="true"></span><span class="visually-hidden">Loading current stocks…</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    @include('partials.defect-dialog')
  </main>
@endsection

@push('scripts')
<script src="{{ asset('js/pos/stocks.js') }}?v={{ asset_version('js/pos/stocks.js') }}"></script>
@endpush

@component('partials.page-startup', ['portal' => 'cashier'])
await loadCashierStocks();
startPageRefresh(loadCashierStocks);
@endcomponent

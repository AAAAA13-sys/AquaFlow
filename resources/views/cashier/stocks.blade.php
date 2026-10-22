@extends('layouts.cashier')

@section('title', 'Stock Availability | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Stock Availability')

@section('cashier-body')
  <main class="admin-main-content cashier-page">
    <header class="cashier-page-header">
      <div>
        <p>Read-only stock levels. Ask your manager to restock low supplies. Final order availability is checked at the POS.</p>
      </div>
      <div>
        <button type="button" id="refreshStocksBtn" onclick="loadCashierStocks()" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
          </svg>
          <span>Refresh</span>
        </button>
        <button type="button" onclick="openDefectReportDialog()" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:6px;margin-left:6px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 9v4M12 17h.01"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
          </svg>
          <span>Report Defect</span>
        </button>
      </div>
    </header>

    <section class="card cashier-ledger cashier-stock-ledger">
      <div class="flex-between" style="margin-bottom:12px;flex-wrap:wrap;gap:8px;">
        <h3 class="panel-heading" style="margin:0;">Supplies &amp; Inventory</h3>
        <span id="cStockCountBadge" class="order-receipt-tag" role="status">Loading…</span>
      </div>

      {{-- Filters --}}
      <div class="filter-toolbar no-print table-filters stock-filters" style="margin-bottom:14px;">
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

      <div class="data-table-wrapper">
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
              <td colspan="4" class="text-center empty-cell" style="padding:2rem;color:var(--text-muted, #64748b);">
                Loading current stocks...
              </td>
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

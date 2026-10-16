@extends('layouts.cashier')

@section('title', 'Current Stocks | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Current Stocks')

@section('cashier-body')
  <main class="admin-main-content cashier-page">
    <header class="cashier-page-header">
      <div>
        <p class="cashier-eyebrow">INVENTORY SNAPSHOT</p>
        <h2>Current stocks</h2>
        <p>Real-time view of inventory levels, consumable supplies, and replenishment alerts.</p>
      </div>
      <div>
        <button type="button" id="refreshStocksBtn" onclick="loadCashierStocks()" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
          </svg>
          <span>Refresh</span>
        </button>
      </div>
    </header>

    <section class="card cashier-ledger">
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
              <th class="num">Safety Stock</th>
              <th class="num">Reorder Point</th>
            </tr>
          </thead>
          <tbody id="cStockBody">
            <tr>
              <td colspan="6" class="text-center empty-cell" style="padding:2rem;color:var(--text-muted, #64748b);">
                Loading current stocks...
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>
@endsection

@push('scripts')
<script>
let cashierInventoryData = [];

function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

async function loadCashierStocks() {
  const badge = document.getElementById('cStockCountBadge');
  if (badge) badge.textContent = 'Updating…';

  try {
    const data = await API.inventory();
    cashierInventoryData = data.inventory || [];
    renderCashierStocksTable();
  } catch (error) {
    console.error('Failed to load cashier stocks:', error);
    if (badge) badge.textContent = 'Error';
    const tbody = document.getElementById('cStockBody');
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="6" class="text-center empty-cell" style="padding:2rem;color:var(--danger, #dc2626);">
        Failed to fetch stock levels. <button type="button" class="btn btn-ghost btn-sm" onclick="loadCashierStocks()">Retry</button>
      </td></tr>`;
    }
  }
}

function renderCashierStocksTable() {
  const tbody = document.getElementById('cStockBody');
  const badge = document.getElementById('cStockCountBadge');
  if (!tbody) return;

  const cat = (document.getElementById('cStockCatFilter')?.value || '').trim().toLowerCase();
  const search = (document.getElementById('cStockSearchInput')?.value || '').trim().toLowerCase();
  const sort = document.getElementById('cStockSortSelect')?.value || 'lowest';

  let filtered = cashierInventoryData.filter(item => {
    if (cat && (item.cat || '').toLowerCase() !== cat) return false;
    if (search && !(item.item || '').toLowerCase().includes(search)) return false;
    return true;
  });

  filtered.sort((a, b) => {
    if (sort === 'lowest') return (a.on ?? 0) - (b.on ?? 0);
    if (sort === 'highest') return (b.on ?? 0) - (a.on ?? 0);
    if (sort === 'name') return (a.item || '').localeCompare(b.item || '');
    return 0;
  });

  if (badge) {
    badge.textContent = `${filtered.length} of ${cashierInventoryData.length} items`;
  }

  if (filtered.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" class="text-center empty-cell" style="padding:2rem;color:var(--text-muted, #64748b);">No stock items match your search or filter.</td></tr>';
    return;
  }

  tbody.innerHTML = filtered.map(item => {
    const isLow = item.needs_reorder || item.status === 'Critical' || item.status === 'Reorder';
    const statusPill = isLow
      ? '<span class="pill pill-bad">Reorder</span>'
      : '<span class="pill pill-ok">OK</span>';

    const unitText = escapeHtml(item.unit || 'pcs');
    const onHand = Number(item.on ?? 0).toLocaleString();
    const ssVal = (item.ss !== null && item.ss !== undefined)
      ? '<b>' + Number(item.ss).toLocaleString() + '</b><span class="num-unit">' + unitText + '</span>'
      : '—';
    const ropVal = (item.rop !== null && item.rop !== undefined)
      ? '<b>' + Number(item.rop).toLocaleString() + '</b><span class="num-unit">' + unitText + '</span>'
      : '—';

    return `
      <tr>
        <td><b>${escapeHtml(item.item)}</b></td>
        <td>${escapeHtml(item.cat || '—')}</td>
        <td class="num"><b>${onHand}</b><span class="num-unit">${unitText}</span></td>
        <td class="text-center">${statusPill}</td>
        <td class="num">${ssVal}</td>
        <td class="num">${ropVal}</td>
      </tr>
    `;
  }).join('');
}
</script>
@endpush

@component('partials.page-startup', ['portal' => 'cashier'])
await loadCashierStocks();
startPageRefresh(loadCashierStocks);
@endcomponent

@extends('layouts.admin')

@section('title', 'Customer Balances | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Customer Balances')

@section('admin-content')
  <section id="s-cust" class="admin-page">
    <header class="page-head">
      <div class="page-head__text">
        <h2 class="page-head__title">Customer balances</h2>
        <p class="page-head__purpose">See who owes the station and open an account to record a payment or print a statement.</p>
      </div>
    </header>
    <div id="customerSummary" class="status-line" aria-label="customers summary"></div>
    <div class="filter-bar no-print" role="group" aria-label="Filter customers">
      <div class="filter-group">
        <label class="form-label" for="custF">Balance status</label>
        <select id="custF" onchange="renderCustTable('', this.value)">
          <option value="">All customers</option>
          <option value="cash">Outstanding balance</option>
        </select>
      </div>
      @include('partials.table-filters', ['target' => 'custBody', 'label' => 'customers', 'refresh' => "renderCustTable('', document.getElementById('custF').value)", 'embedded' => true])
    </div>

    <div class="card data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>Customer</th>
            <th class="num">Visits</th>
            <th class="num">Cash owed</th>
            <th>Last visit</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody id="custBody"></tbody>
      </table>
    </div>
    <div id="statementSheet" class="sheet" data-station="{{ $stationName ?? 'AquaFlow' }}" hidden></div>
  </section>

  <!-- Lateral Detail Drawer -->
  @include('partials.editor-drawer', ['drawerId' => 'drawer', 'bodyId' => 'drawerBody', 'title' => 'Customer Detail', 'closeAction' => 'closeDrawer()'])
@endsection

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/erp.css') }}?v={{ asset_version('css/erp.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('js/admin/customer-statement.js') }}?v={{ asset_version('js/admin/customer-statement.js') }}"></script>
@endpush

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Customer Balances'])
renderCustTable('', '');
startPageRefresh(async () => { await loadFromServer(); const filters = currentCustFilters(); renderCustTable(filters.q, filters.f); renderSidebarBadges(); renderStamp(); });
@endcomponent

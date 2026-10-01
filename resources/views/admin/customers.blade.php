@extends('layouts.admin')

@section('title', 'Customer Liabilities | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Customer Liabilities')

@section('admin-content')
  <section id="s-cust">
    <h2 class="auth-title">Customer Liabilities</h2>
    <div class="filter-toolbar no-print">
      <input id="custQ" oninput="renderCustTable(this.value, document.getElementById('custF').value)" class="form-input search-input" placeholder="Search customers">
      <select id="custF" onchange="renderCustTable(document.getElementById('custQ').value, this.value)">
        <option value="">All</option>
        <option value="bottles">Has unreturned bottles</option>
        <option value="cash">Has cash debt</option>
      </select>
    </div>
    <div class="card data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>Customer</th>
            <th class="num">Visits</th>
            <th>Bottles given S/R</th>
            <th>Bottles returned S/R</th>
            <th>Owed bottles S/R</th>
            <th class="num">Cash owed</th>
            <th>Last visit</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="custBody"></tbody>
      </table>
    </div>
  </section>

  <!-- Lateral Detail Drawer -->
  <div id="drawer" class="side-drawer hidden">
    <div class="flex-between order-type-selector">
      <h3 class="panel-heading">Customer Detail</h3>
      <button onclick="closeDrawer()" class="btn btn-ghost btn-sm">Close</button>
    </div>
    <div id="drawerBody"></div>
  </div>
@endsection

@push('scripts')
  <script>
    (async () => {
      window.SESSION = await bootAdminPortal('Customer Liabilities');
      if (SESSION) {
        renderCustTable('', '');
      }
    })();
  </script>
@endpush

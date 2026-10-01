@extends('layouts.admin')

@section('title', 'Sales & POS Logs | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Sales & POS Logs')

@section('admin-content')
  <section id="s-sales">
    <h2 class="auth-title">Sales &amp; POS Logs</h2>
    <div class="card filter-toolbar no-print">
      <div class="filter-group">
        <label class="form-label">When</label>
        <select id="fDate" onchange="renderSalesTable()">
          <option value="0">Today</option>
          <option value="7">Last 7 days</option>
          <option value="30">Last 30 days</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="form-label">Order type</label>
        <select id="fChannel" onchange="renderSalesTable()">
          <option>All</option>
          <option>Walk-in</option>
          <option>Delivery</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="form-label">Payment</label>
        <select id="fPay" onchange="renderSalesTable()">
          <option>All</option>
          <option>Cash</option>
          <option>Account</option>
        </select>
      </div>
      <button onclick="exportSalesCSV()" class="btn btn-ghost">Export CSV</button>
    </div>

    <div id="salesSum" class="orders-queue-list order-type-selector"></div>

    <div class="card data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>OR</th>
            <th>Time</th>
            <th>Customer</th>
            <th>Order type</th>
            <th>Gallons</th>
            <th class="num">Total</th>
            <th>Pay</th>
            <th>Cashier</th>
          </tr>
        </thead>
        <tbody id="salesBody"></tbody>
      </table>
    </div>
    <p class="auth-footer-text text-center">Every transaction is recorded with the active cashier and completion timestamp.</p>
  </section>
@endsection

@push('scripts')
  <script>
    (async () => {
      window.SESSION = await bootAdminPortal('Sales & POS Logs');
      if (SESSION) {
        renderSalesTable();
      }
    })();
  </script>
@endpush

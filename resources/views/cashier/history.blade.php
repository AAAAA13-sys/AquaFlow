@extends('layouts.cashier')

@section('title', 'Sales & Transactions | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Sales & Transaction History')

@section('cashier-body')
  <main class="admin-main-content">
    <div class="card filter-toolbar no-print">
      <div class="filter-group">
        <label class="form-label">When</label>
        <select id="hDate" onchange="renderHistoryPage()">
          <option value="0">Today</option>
          <option value="7">Last 7 days</option>
          <option value="30">Last 30 days</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="form-label">Order type</label>
        <select id="hType" onchange="renderHistoryPage()">
          <option>All</option>
          <option>Walk-in</option>
          <option>Delivery</option>
          <option>Debt Payment</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="form-label">Payment</label>
        <select id="hPay" onchange="renderHistoryPage()">
          <option>All</option>
          <option>Cash</option>
          <option>Account</option>
        </select>
      </div>
      <button onclick="exportHistoryCsv()" class="btn btn-ghost">Export CSV</button>
    </div>

    <div id="hSum" class="orders-queue-list order-type-selector"></div>

    <div class="card data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>OR</th>
            <th>Date</th>
            <th>Time</th>
            <th>Customer</th>
            <th>Type</th>
            <th>Gallons</th>
            <th class="num">Vatable</th>
            <th class="num">VAT</th>
            <th class="num">Total</th>
            <th>Pay</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="hBody"></tbody>
      </table>
    </div>

    <p class="auth-footer-text text-center">Prices are VAT inclusive; the vatable amount and tax are derived from the total.</p>
  </main>

  {{-- Reprint uses the same thermal receipt as the terminal --}}
  <div id="receipt" class="overlay-modal hidden">
    <div class="receipt-card">
      <div class="receipt-header">
        <h2 class="receipt-brand">{{ strtoupper($stationName ?? 'AQUAFLOW STATION') }}</h2>
        <p class="receipt-sub">Water Refilling Station</p>
        <p id="rMeta" class="receipt-meta"></p>
      </div>
      <div id="rBody" class="receipt-body"></div>
      <div class="receipt-footer">
        THANK YOU FOR YOUR PATRONAGE!<br>
        VAT inclusive sales. This serves as your official receipt.
      </div>
      <div class="button-grid-2 no-print order-type-selector">
        <button onclick="printReceipt()" class="btn btn-ghost">Print</button>
        <button onclick="closeDrawerReceipt()" class="btn btn-primary">Close</button>
      </div>
    </div>
  </div>
@endsection

@push('scripts')
  <script>
    (async () => {
      window.SESSION = await bootCashierPortal();
      if (SESSION) {
        renderHistoryPage();
      }
    })();
  </script>
@endpush

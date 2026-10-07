@extends('layouts.cashier')

@section('title', 'Sales & Transactions | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Sales & Transaction History')

@section('cashier-body')
  <main class="admin-main-content cashier-page">
    <header class="cashier-page-header"><div><p class="cashier-eyebrow">TRANSACTION RECORDS</p><h2>Sales history</h2><p>Review completed sales, account charges, and recorded payments.</p></div><button onclick="exportHistoryCsv()" class="btn btn-primary">Export CSV</button></header>
    <div id="hSum" class="cashier-summary" aria-label="Filtered transaction summary"></div>
    <div class="card cashier-filters no-print">
      <div class="filter-group">
        <label class="form-label" for="hDate">Period</label>
        <select id="hDate" onchange="renderHistoryPage()">
          <option value="0">Today</option>
          <option value="7">Last 7 days</option>
          <option value="30">Last 30 days</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="form-label" for="hType">Order type</label>
        <select id="hType" onchange="renderHistoryPage()">
          <option>All</option>
          <option>Walk-in</option>
          <option>Delivery</option>
          <option>Debt Payment</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="form-label" for="hPay">Payment</label>
        <select id="hPay" onchange="renderHistoryPage()">
          <option>All</option>
          <option>Cash</option>
          <option>Account</option>
        </select>
      </div>
      @include('partials.table-filters', ['target' => 'hBody', 'label' => 'transactions', 'refresh' => "renderHistoryPage()", 'embedded' => true])

    </div>



    <section class="card cashier-ledger"><h3 class="panel-heading">Transactions</h3><div class="data-table-wrapper">
      <table class="clean">
        <caption class="visually-hidden">Recorded transactions for the selected period. Debt payments are shown in italic and are excluded from the sales revenue figure above.</caption>
        <thead>
          <tr>
            <th>OR</th>
            <th>Date</th>
            <th>Time</th>
            <th>Customer</th>
            <th>Type</th>
            <th>Gallons</th>
            <th class="num">Vatable @include('partials.tip', [
              'text' => 'The part of the total that tax is charged on. AquaFlow prices are VAT-inclusive, so this is worked backwards from the total you were charged rather than added on top of it.',
              'label' => 'What does Vatable mean?',
            ])</th>
            <th class="num">VAT @include('partials.tip', [
              'text' => 'Value Added Tax at 12%. It is already included in the price the customer paid, so this column shows how much of the total is tax.',
              'label' => 'What does VAT mean?',
            ])</th>
            <th class="num col-total">Total</th>
            <th>Pay</th>
            <th>Receipt</th>
          </tr>
        </thead>
        <tbody id="hBody"></tbody>
      </table>
    </div><p id="ledgerScrollNote" class="cashier-scroll-note">Scroll the table sideways to see payment method and receipt actions.</p><nav id="historyPages" class="cashier-pagination" aria-label="Transaction pages"></nav></section>

    <p class="auth-footer-text text-center">Prices are VAT inclusive; the vatable amount and tax are derived from the total.</p>
  </main>

  {{-- Reprint uses the same thermal receipt as the terminal --}}
  @include('partials.receipt-modal', ['closeAction' => 'closeDrawerReceipt()', 'closeLabel' => 'Close'])
@endsection

@component('partials.page-startup', ['portal' => 'cashier'])
renderHistoryPage();
startPageRefresh(renderHistoryPage);
@endcomponent

@extends('layouts.cashier')

@section('title', 'Sales & Receipts | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Sales & Receipts')

@section('page-actions')
<div class="page-head__actions">
        <button type="button" onclick="exportHistoryCsv()" class="btn btn-secondary">Export CSV</button>
      </div>
@endsection

@section('cashier-body')
  <main id="main" tabindex="-1" class="admin-main-content cashier-page cashier-history-page">
    <header class="page-head">
      <div class="page-head__text">
        <h2 class="page-head__title">Sales and receipts</h2>
        <p class="page-head__purpose">{{ auth()->user()?->isAdmin() ? 'Review station sales, account charges, and recorded payments.' : 'Review sales, account charges, and payments recorded by your account.' }}</p>
      </div>

    </header>

    <div id="hSum" class="status-line" aria-label="Filtered transaction summary"></div>

    <section class="card cashier-ledger cashier-history-ledger" aria-labelledby="historyLedgerTitle">
      <div class="cashier-ledger-heading">
        <h3 id="historyLedgerTitle" class="panel-heading">Transactions</h3>
        <button type="button" id="toggleFiltersBtn" class="btn btn-secondary btn-sm cashier-filter-toggle" aria-expanded="false" aria-controls="cashierFiltersPanel" onclick="toggleCashierFilters()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
          <span>Filters</span>
        </button>
      </div>

      <div id="cashierFiltersPanel" class="no-print hidden cashier-filter-panel">
        <div class="filter-bar" role="group" aria-label="Filter transactions">
          <div class="filter-group">
            <label class="form-label" for="hFrom">From</label>
            <input id="hFrom" type="datetime-local" step="60" value="{{ now()->startOfDay()->format('Y-m-d\TH:i') }}" data-default="{{ now()->startOfDay()->format('Y-m-d\TH:i') }}" onchange="renderHistoryPage()">
          </div>
          <div class="filter-group">
            <label class="form-label" for="hTo">Through</label>
            <input id="hTo" type="datetime-local" step="60" value="{{ now()->endOfDay()->format('Y-m-d\TH:i') }}" data-default="{{ now()->endOfDay()->format('Y-m-d\TH:i') }}" onchange="renderHistoryPage()">
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
          @include('partials.table-filters', ['target' => 'hBody', 'label' => 'transactions', 'refresh' => "renderHistoryPage()", 'embedded' => true])
        </div>
      </div>
      <div class="data-table-wrapper" tabindex="0" role="region" aria-label="Transactions table">
        <table class="clean">
          <caption class="visually-hidden">Recorded transactions for the selected period. Debt payments are shown in italic and are excluded from the sales revenue figure above.</caption>
          <thead>
            <tr>
              <th>OR</th>
              <th>Date</th>
              <th>Time</th>
              <th>Customer</th>
              <th>Type &amp; Items</th>
              <th class="num col-vat">Vatable @include('partials.tip', [
                'text' => 'The part of the total that tax is charged on. AquaFlow prices are VAT-inclusive, so this is worked backwards from the total you were charged rather than added on top of it.',
                'label' => 'What does Vatable mean?',
              ])</th>
              <th class="num col-vat">VAT @include('partials.tip', [
                'text' => 'Value Added Tax at 12%. It is already included in the price the customer paid, so this column shows how much of the total is tax.',
                'label' => 'What does VAT mean?',
              ])</th>
              <th class="num col-total">Total</th>
              <th>Pay</th>
              <th class="text-center">Receipt</th>
            </tr>
          </thead>
          <tbody id="hBody"></tbody>
        </table>
      </div>
      <p id="ledgerScrollNote" class="cashier-scroll-note">Scroll the table sideways to see payment method and receipt actions.</p>
      <nav id="historyPages" class="cashier-pagination" aria-label="Transaction pages"></nav>
    </section>

    <p class="auth-footer-text text-center">Prices are VAT inclusive; the vatable amount and tax are derived from the total.</p>
  </main>

  {{-- Reprint uses the same thermal receipt as the terminal --}}
  @include('partials.receipt-modal', ['closeAction' => 'closeDrawerReceipt()', 'closeLabel' => 'Close'])
@endsection

@component('partials.page-startup', ['portal' => 'cashier'])
renderHistoryPage();
startPageRefresh(renderHistoryPage);
@endcomponent

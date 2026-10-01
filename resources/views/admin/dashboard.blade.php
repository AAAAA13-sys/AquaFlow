@extends('layouts.admin')

@section('title', 'Dashboard | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Station Dashboard & Analytics')

@section('admin-content')
  <section id="s-dash">
    <div class="kpi-cards-grid">
      <div class="kpi-card-body kpi-blue">
        <p class="kpi-label">Today's Revenue (PHP)</p>
        <p class="kpi-value" id="kRev">-</p>
        <p class="kpi-subtext" id="kRevSub">-</p>
      </div>
      <div class="kpi-card-body kpi-green">
        <p class="kpi-label">ARIMA Forecasted Demand</p>
        <p class="kpi-value" id="kGal">-</p>
        <p class="kpi-subtext" id="kGalSub">-</p>
      </div>
      <div class="kpi-card-body kpi-red">
        <p class="kpi-label">CRITICAL ALERT</p>
        <p class="kpi-value" id="invHealth">-</p>
        <p class="kpi-subtext">low stock, order now</p>
      </div>
      <div class="kpi-card-body kpi-yellow">
        <p class="kpi-label">Customer Liabilities (Unreturned)</p>
        <p class="kpi-value" id="kLia">-</p>
        <p class="kpi-subtext" id="kLiaSub">-</p>
      </div>
    </div>

    <div class="dashboard-row-3col">
      <div class="card">
        <h3 class="panel-heading">ARIMA Time-Series Demand Forecasting Center</h3>
        <p class="auto-deduct-caption">ARIMA Demand Forecasting Engine - Daily Water Refill Gallon Projections</p>
        <canvas id="chDemand" height="120"></canvas>
        <div id="fcMetaDash" class="orders-queue-list order-type-selector"></div>
        <button onclick="showSection('arima')" class="btn btn-primary btn-sm order-type-selector">Open ARIMA Analytics Tab</button>
      </div>
      <div class="card">
        <h3 class="panel-heading">Spoon-Fed Restock Advisories</h3>
        <div id="advisory" class="custody-summary-box"></div>
      </div>
    </div>

    <div class="dashboard-row-2col">
      <div class="card">
        <h3 class="panel-heading">Consumable Inventory &amp; Dynamic ROP Thresholds</h3>
        <div class="data-table-wrapper">
          <table class="clean">
            <thead>
              <tr>
                <th>Item Description</th>
                <th>Category</th>
                <th class="num">Current Stock</th>
                <th class="num">ARIMA Dynamic ROP</th>
                <th>Lead Time</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="invBodyDash"></tbody>
          </table>
        </div>
        <button onclick="showSection('inv')" class="btn btn-ghost btn-sm order-type-selector">Manage Inventory &amp; Reordering</button>
      </div>

      <div class="card">
        <h3 class="panel-heading">Returnable Container Asset Custody Ledger</h3>
        <div class="button-grid-3">
          <div class="ledger-box kpi-blue"><b>5-Gal Slim Containers</b><p id="ledgerSlim" class="kpi-value">-</p></div>
          <div class="ledger-box kpi-green"><b>5-Gal Round Containers</b><p id="ledgerRound" class="kpi-value">-</p></div>
          <div class="ledger-box kpi-yellow"><b>Pending Bottles (All)</b><p id="ledgerPend" class="kpi-value">-</p></div>
        </div>
        <p class="section-label">Top Outstanding Customer Bottle Liabilities</p>
        <div class="data-table-wrapper">
          <table class="clean">
            <thead>
              <tr>
                <th>Customer Profile</th>
                <th class="num">Pending</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="topLiaBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="dashboard-row-4col">
      <div class="card">
        <h3 class="panel-heading">What the Trend Tells You</h3>
        <div id="insDemand"></div>
      </div>
      <div class="card">
        <h3 class="panel-heading">Stock Levels: Days Left</h3>
        <div id="insRunway"></div>
      </div>
      <div class="card">
        <h3 class="panel-heading">Who to Visit First</h3>
        <div id="insCollect"></div>
      </div>
      <div class="card">
        <h3 class="panel-heading">To-Do List for Today</h3>
        <div id="insActions" class="custody-summary-box"></div>
      </div>
    </div>
  </section>
@endsection

@push('scripts')
  <script>
    (async () => {
      window.SESSION = await bootAdminPortal('Station Dashboard & Analytics');
      if (SESSION) {
        renderInvTable();
        renderInsights();
        renderDemandChart();
      }
    })();
  </script>
@endpush

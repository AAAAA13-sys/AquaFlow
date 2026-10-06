@extends('layouts.admin')

@section('title', 'Demand Forecast | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Demand Forecast')

@section('admin-content')
  <section id="s-arima">
    <h2 class="auth-title">Demand Forecast</h2>
    <p class="auth-description">
        Using the ARIMA model, your past sales are analyzed to predict how much you will need for the next 7 days, allowing you to buy and prepare in advance.
    </p>

    <div class="card filter-toolbar no-print">
      <div class="filter-group">
        <label class="form-label">Show by</label>
        <select id="fHorizon" onchange="renderArimaChart(); renderForecast()">
          <option>Daily</option>
          <option>Weekly</option>
          <option>Monthly</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="form-label">Product</label>
        <select id="fSeries" onchange="renderArimaChart(); renderForecast()">
          <option>Refill gallons</option>
          <option>Heat Shrink Seals</option>
          <option>Non-Spill Caps</option>
          <option>Sediment Filters</option>
        </select>
      </div>
      <button id="btnRunForecast" onclick="runForecast()" class="btn btn-primary">Update Forecast</button>
      <button onclick="exportReport()" class="btn btn-ghost">Export Report</button>
    </div>

    <div class="dashboard-row-2col">
      <div class="card">
        <h3 class="panel-heading">What You Will Need <span class="term-hint">ARIMA demand forecast</span></h3>
        <canvas id="chArima" height="140"></canvas>
        <div id="fcMeta" class="orders-queue-list order-type-selector"></div>
      </div>
      <div class="card">
        <h3 class="panel-heading">What To Do This Week</h3>
        <div id="fcTable"></div>
      </div>
    </div>
  </section>
@endsection

@push('scripts')
  <script>
    (async () => {
      window.SESSION = await bootAdminPortal('ARIMA Analytics');
      if (SESSION) {
        renderForecast();
        renderArimaChart();
      }
    })();
  </script>
@endpush

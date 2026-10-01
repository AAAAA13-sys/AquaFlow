@extends('layouts.admin')

@section('title', 'ARIMA Analytics | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'ARIMA Analytics')

@section('admin-content')
  <section id="s-arima">
    <h2 class="auth-title">ARIMA Analytics <span class="pill pill-info">thesis model</span></h2>
    <p class="auth-description">Dedicated ARIMA forecasting suite analyzing historical volume to project next 7 days demand.</p>

    <div class="card filter-toolbar no-print">
      <div class="filter-group">
        <label class="form-label">Time frame</label>
        <select id="fHorizon" onchange="renderArimaChart(); renderForecast()">
          <option>Daily</option>
          <option>Weekly</option>
          <option>Monthly</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="form-label">Item</label>
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
        <h3 class="panel-heading">ARIMA Demand Forecasting Engine</h3>
        <canvas id="chArima" height="140"></canvas>
        <div id="fcMeta" class="orders-queue-list order-type-selector"></div>
      </div>
      <div class="card">
        <h3 class="panel-heading">Next 7 Days - Action Recommendations</h3>
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

@extends('layouts.admin')

@section('title', 'Demand Forecast | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Demand Forecast')

@section('admin-content')
  <section id="s-arima" class="admin-page">
    @include('partials.admin-page-header', ['eyebrow' => 'PLANNING', 'heading' => 'Demand Forecast', 'description' => 'Use sales history to estimate demand and plan supplies for the next seven days.'])

<div class="card filter-toolbar no-print">
      <div class="filter-group">
        <label class="form-label" for="fHorizon">Time view</label>
        <select id="fHorizon" onchange="saveForecastView()">
          <option>Daily</option>
          <option>Weekly</option>
          <option>Monthly</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="form-label" for="fSeries">Forecast series</label>
        <select id="fSeries" onchange="renderArimaChart(); renderForecast()">
          <option>Refill gallons</option>
          <option>Heat Shrink Seals</option>
          <option>Non-Spill Caps</option>
          <option>Sediment Filters</option>
          <option value="SHRINK_SLIM">Slim jug seals</option>
          <option value="SHRINK_ROUND">Round jug seals</option>
          <option value="CLEAR_COVER">Clear covers</option>
        </select>
      </div>
      <button id="btnRunForecast" onclick="runForecast()" class="btn btn-primary">Update Forecast</button>
      <button onclick="exportReportPDF()" class="btn btn-ghost">Print / Save PDF</button>
      <button onclick="exportReport()" class="btn btn-ghost">Export Report</button>
    </div>

    <div class="dashboard-row-2col forecast-layout">
      <div class="card">
        <h3 class="panel-heading">Demand outlook <span class="term-hint">ARIMA demand forecast</span></h3>
        <p class="settings-help">Daily view shows the latest 30 days of recorded demand alongside the stored forecast.</p>
        <canvas id="chArima" height="160"></canvas>
        <div id="fcMeta" class="orders-queue-list order-type-selector"></div>
      </div>
      <div class="card">
        <h3 class="panel-heading">Supply planning</h3>
        <div id="fcTable"></div>
      </div>
    </div>
  </section>
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Demand Forecast'])
restoreForecastView();
renderForecast();
renderArimaChart();
@endcomponent

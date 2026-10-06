@extends('layouts.admin')

@section('title', 'Dashboard | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Overview')

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/overview.css') }}?v={{ asset_version('css/overview.css') }}">
@endpush

@section('admin-content')
  <section id="s-dash">

    <header class="overview-header">
      <div><p class="overview-eyebrow">STATION OVERVIEW</p><h2>Your station at a glance</h2><p>Today's sales, upcoming demand, and the work that needs your attention.</p></div>
      <div class="overview-shortcuts"><a href="{{ url('/admin/sales') }}" class="btn btn-secondary">View sales</a><a href="{{ url('/cashier') }}" class="btn btn-primary">Open POS &rarr;</a></div>
    </header>

    <div class="kpi-cards-grid">
      <div class="kpi-card-body kpi-blue">
        <p class="kpi-label">Sales Revenue Today</p>
        <p class="kpi-value" id="kRev">-</p>
        <p class="kpi-subtext" id="kRevSub">-</p>
      </div>
      <div class="kpi-card-body kpi-green">
        <p class="kpi-label">Expected Sales Next 7 Days</p>
        <p class="kpi-value" id="kGal">-</p>
        <p class="kpi-subtext" id="kGalSub">-</p>
      </div>
      <div class="kpi-card-body kpi-red">
        <p class="kpi-label">Needs Your Attention</p>
        <p class="kpi-value" id="invHealth">-</p>
        <p class="kpi-subtext" id="kStockSub">Stock levels are loading</p>
      </div>
      <div class="kpi-card-body kpi-yellow">
        <p class="kpi-label">Customers Who Owe Money</p>
        <p class="kpi-value" id="kLia">-</p>
        <p class="kpi-subtext" id="kLiaSub">-</p>
        <p class="kpi-micro" id="kLiaTop"></p>
      </div>
    </div>

    <section class="card overview-priorities" aria-labelledby="overviewPrioritiesTitle">
      <div class="overview-panel-head"><div><p class="overview-eyebrow">NEXT STEPS</p><h3 id="overviewPrioritiesTitle">Today's priorities</h3></div><span class="overview-hint">Stock · Collections · Planning</span></div>
      <div id="insActions" class="overview-actions"></div>
    </section>

    <div class="dash-row-chart">
      <div class="card">
        <h3 class="panel-heading">Demand outlook <span class="term-hint">next 7 days</span></h3>
        <p class="auto-deduct-caption">Compare the last 30 days of refill sales with the upcoming forecast.</p>
        <canvas id="chDemand" height="120"></canvas>
        <div id="fcMetaDash" class="orders-queue-list order-type-selector"></div>
        {{-- Tertiary: this is a deep jump into analytics, not a committed
             action. It must not compete visually with "Make a Purchase Request". --}}
        <button onclick="showSection('arima')" class="btn btn-tertiary btn-sm order-type-selector">Open Demand Forecast</button>
      </div>
      {{-- Rail: sizes to its own content instead of stretching to the chart's
           height, which is what produced the empty block. --}}
      <div class="card card--rail">
        <h3 class="panel-heading">Restock Advisories</h3>
        <div id="advisory" class="overview-advisories"></div>
        <button type="button" onclick="draftPO()" class="btn btn-secondary btn-sm overview-purchase">Make a Purchase Request</button>
      </div>
    </div>

    {{-- Supporting sales, inventory and collection details. --}}
    <div class="dashboard-row-3col">
      <div class="card">
        <h3 class="panel-heading">Sales patterns</h3>
        <div id="insDemand"></div>
      </div>
      <div class="card">
        <h3 class="panel-heading">Stock runway</h3>
        <p class="auto-deduct-caption">The four items with the shortest stock coverage.</p>
        <div id="insRunway"></div>
      </div>
      <div class="card">
        <h3 class="panel-heading">Collection priorities</h3>
        <div id="insCollect"></div>
      </div>
    </div>

    {{-- 3. REFERENCE. The tables behind the cards above. --}}
    <div class="dashboard-row-2col overview-reference">
      <div class="card">
        <h3 class="panel-heading">Stock Check <span class="term-hint">reorder points</span></h3>
        <div id="invFilterTabs" class="filter-tabs no-print" role="group" aria-label="Filter stock by type"></div>
        @include('partials.table-filters', ['target' => 'invBodyDash', 'label' => 'stock', 'refresh' => 'renderInvTable()'])
        <div class="data-table-wrapper">
          <table class="clean">
            <thead>
              <tr>
                <th>Item <span class="term-hint">type & supplier</span></th>
                <th class="num">In Stock</th>
                <th class="num">Reorder Below <span class="term-hint">ROP</span></th>
                <th class="num">Lasts</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="invBodyDash"></tbody>
          </table>
        </div>
        <button onclick="showSection('inv')" class="btn btn-tertiary btn-sm order-type-selector">Manage Stock &amp; Reordering</button>
      </div>

      <div class="card">
        <h3 class="panel-heading">New jugs &amp; customer balances</h3>
        <div class="overview-jug-stock">
          <div class="ledger-box kpi-blue"><b>New Slim Jugs</b><p id="ledgerSlim" class="kpi-value">-</p></div>
          <div class="ledger-box kpi-green"><b>New Round Jugs</b><p id="ledgerRound" class="kpi-value">-</p></div>
        </div>
        <p class="section-label">Outstanding Customer Balances</p>
        @include('partials.table-filters', ['target' => 'topLiaBody', 'label' => 'customer balances', 'refresh' => 'renderLedger()'])
        <div class="data-table-wrapper">
          <table class="clean">
            <thead>
              <tr>
                <th>Customer</th>
                <th class="num">Outstanding</th>
                <th class="num">Action</th>
              </tr>
            </thead>
            <tbody id="topLiaBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
@endsection

{{-- Customer balance reminder drawer. --}}
<div id="remindDrawer" class="af-drawer" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="remindDrawerTitle">
  <div class="af-drawer-backdrop" data-action="close" onclick="closeRemindDrawer()"></div>
  <div class="af-drawer-panel">
    <header class="af-drawer-head">
      <h3 id="remindDrawerTitle" class="af-drawer-title">Send a reminder</h3>
      <button type="button" class="af-drawer-close" data-action="close" onclick="closeRemindDrawer()" aria-label="Close">&times;</button>
    </header>

    <div class="af-drawer-body">
      <div class="af-drawer-party">
        <span class="af-drawer-name" data-field="name"></span>
        <span class="af-drawer-contact" data-field="contact"></span>
      </div>
      <p class="af-drawer-summary" data-field="summary"></p>

      <label class="af-drawer-label" for="remindTemplate">Message</label>
      <pre class="af-drawer-template" id="remindTemplate" data-field="template"></pre>
      <p class="af-drawer-hint">Copy this reminder, or open WhatsApp to review it before sending.</p>
    </div>

    <footer class="af-drawer-foot">
      <button type="button" class="btn btn-secondary" data-action="copy" onclick="copyReminder()">Copy</button>
      <a class="btn btn-primary" data-action="send" target="_blank" rel="noopener">Send via WhatsApp</a>
    </footer>
  </div>
</div>

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Overview'])
renderInvTable();
renderInsights();
renderDemandChart();
@endcomponent

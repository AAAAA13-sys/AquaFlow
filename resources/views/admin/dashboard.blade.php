@extends('layouts.admin')

@section('title', 'Dashboard | ' . ($stationName ?? 'AquaFlow'))
@section('meta-description', 'Sales, stock and demand forecast for ' . ($stationName ?? 'AquaFlow') . ' water refilling station.')
@section('topbar-title', 'Overview')

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/overview.css') }}?v={{ asset_version('css/overview.css') }}">
@endpush

@section('admin-content')
  <section id="s-dash">


    <section class="card onboarding-checklist-card" id="onboardingChecklist">
      <div class="onboarding-card-head">
        <div>
          <h3 class="onboarding-card-title">Getting Started</h3>
          <p class="onboarding-card-desc">Essential setup steps for launching your water station operations.</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <span id="onboardingProgressBadge" class="pill pill-ok">Setup Guide</span>
          <button type="button" onclick="toggleOnboardingChecklist()" class="btn btn-ghost btn-sm" id="btnToggleOnboarding" style="padding:4px 10px;min-height:30px;font-size:12px;">Dismiss</button>
        </div>
      </div>
      <div class="onboarding-steps-grid" id="onboardingStepsGrid">
        <a href="{{ url('/admin/inventory') }}" class="onboarding-step-item" id="stepInventory">
          <div class="onboarding-step-icon" aria-hidden="true">1</div>
          <div class="onboarding-step-text">
            <span class="onboarding-step-label">Stock & Supplies</span>
            <span class="onboarding-step-sub">Check gallon catalog, caps, and water inventory items.</span>
          </div>
        </a>
        <a href="{{ url('/admin/suppliers') }}" class="onboarding-step-item" id="stepSuppliers">
          <div class="onboarding-step-icon" aria-hidden="true">2</div>
          <div class="onboarding-step-text">
            <span class="onboarding-step-label">Connect Suppliers</span>
            <span class="onboarding-step-sub">Register water filtration & consumable supply partners.</span>
          </div>
        </a>
        <a href="{{ url('/admin/users') }}" class="onboarding-step-item" id="stepStaff">
          <div class="onboarding-step-icon" aria-hidden="true">3</div>
          <div class="onboarding-step-text">
            <span class="onboarding-step-label">Staff & Access</span>
            <span class="onboarding-step-sub">Create admin and cashier accounts.</span>
          </div>
        </a>
        <a href="{{ url('/admin/settings') }}" class="onboarding-step-item" id="stepSettings">
          <div class="onboarding-step-icon" aria-hidden="true">4</div>
          <div class="onboarding-step-text">
            <span class="onboarding-step-label">Station Settings</span>
            <span class="onboarding-step-sub">Verify station name, receipt headers, and business settings.</span>
          </div>
        </a>
        <a href="{{ url('/cashier') }}" class="onboarding-step-item" id="stepPos">
          <div class="onboarding-step-icon" aria-hidden="true">5</div>
          <div class="onboarding-step-text">
            <span class="onboarding-step-label">Open POS Terminal</span>
            <span class="onboarding-step-sub">Run a test walk-in refill or customer transaction.</span>
          </div>
        </a>
      </div>
    </section>

    {{-- KPI values start as skeletons rather than a literal "-". A dash reads as
             a real zero to an owner glancing at the screen; a shimmer reads as
             "not loaded yet". The ids are kept: renderInsights() overwrites
             each element's textContent, which discards the skeleton span. --}}
    <div class="kpi-cards-grid" id="kpiGrid" aria-busy="true">
      <div class="kpi-card-body kpi-blue">
        <p class="kpi-label">Sales Revenue Today</p>
        <p class="kpi-value" id="kRev"><span class="af-skeleton" style="width:8ch;height:1.2em"></span></p>
        <p class="kpi-subtext" id="kRevSub"><span class="af-skeleton" style="width:12ch"></span></p>
        {{-- Trend direction lives outside #kRev because that element is replaced
             by textContent when the figure arrives. --}}
        <span class="kpi-trend" id="kRevTrend" aria-hidden="true"></span>
        <a class="kpi-action" href="{{ url('/admin/sales') }}">View sales &rarr;</a>
      </div>
      <div class="kpi-card-body kpi-green">
        <p class="kpi-label">Expected Sales Next 7 Days</p>
        <p class="kpi-value" id="kGal"><span class="af-skeleton" style="width:7ch;height:1.2em"></span></p>
        <p class="kpi-subtext" id="kGalSub"><span class="af-skeleton" style="width:22ch"></span></p>
        <a class="kpi-action" href="{{ url('/admin/arima') }}">View forecast &rarr;</a>
      </div>
      <div class="kpi-card-body kpi-red">
        <p class="kpi-label">Needs Your Attention</p>
        <p class="kpi-value" id="invHealth"><span class="af-skeleton" style="width:3ch;height:1.2em"></span></p>
        <p class="kpi-subtext" id="kStockSub">Stock levels are loading</p>
        <a class="kpi-action" id="kStockAction" href="{{ url('/admin/inventory') }}">View stock &rarr;</a>
      </div>
      <div class="kpi-card-body kpi-yellow">
        <p class="kpi-label">Customers Who Owe Money</p>
        <p class="kpi-value" id="kLia"><span class="af-skeleton" style="width:8ch;height:1.2em"></span></p>
        <p class="kpi-subtext" id="kLiaSub"><span class="af-skeleton" style="width:16ch"></span></p>
        <p class="kpi-micro" id="kLiaTop"></p>
        <a class="kpi-action" href="{{ url('/admin/customers') }}">View balances &rarr;</a>
      </div>
    </div>

    {{-- The "Today's priorities" strip used to sit here. It restated the four KPI
         cards directly above it - the same stock state, the same customer debt,
         the same forecast total, each with a sentence of explanation - so the
         owner read every figure twice before reaching anything new. The KPI cards
         now carry the matching action link and this band is gone. --}}

    <div class="dash-row-chart" id="dashChartRow">
      <div class="card">
        <h3 class="panel-heading">Expected Sales Next Week</h3>
        <p class="auto-deduct-caption">Compare the last 30 days of refill sales with the upcoming forecast.</p>
        {{-- Canvas is invisible to assistive tech without a name. The figures
             behind it are already rendered as text in #insDemand below, so the
             label points there rather than restating them. --}}
        <canvas id="chDemand" height="120" role="img"
                aria-label="Demand outlook chart: 30 days of refill sales against the next 7 days of forecast. Sales trends and averages are listed below under How Sales Are Going."></canvas>
        <div id="fcMetaDash" class="orders-queue-list order-type-selector"></div>
        <h4 class="panel-heading">How Sales Are Going</h4>
        <div id="insDemand"></div>
        {{-- Tertiary: this is a deep jump into analytics, not a committed
             action. It must not compete visually with "Make a Purchase Request". --}}
        <button onclick="showSection('arima')" class="btn btn-tertiary btn-sm order-type-selector">Open Demand Forecast</button>
      </div>
      {{-- The forecast sets this row's height; the advisory list scrolls within it. --}}
      <div class="card card--rail" id="advisoryRail">
        <h3 class="panel-heading">Things to Buy</h3>
        <div id="advisory" class="overview-advisories" tabindex="0" role="region" aria-label="Things to buy"></div>
        <button type="button" onclick="draftPO()" class="btn btn-secondary btn-sm overview-purchase">Make a Purchase Request</button>
      </div>
    </div>

    {{-- 3. REFERENCE. The tables behind the cards above. --}}
    <div class="dashboard-row-2col overview-reference">
      <div class="card">
        <h3 class="panel-heading">Jug Stock &amp; Refill Supplies</h3>
        <p class="auto-deduct-caption">New Slim Jugs: <strong id="ledgerSlim">Loading</strong> &middot; New Round Jugs: <strong id="ledgerRound">Loading</strong></p>
        <div class="filter-toolbar table-filters stock-filters no-print">
          <div id="invFilterTabs" class="filter-group"></div>
          <div class="filter-group"><label for="invBodyDashSearch">Search</label>
            <input id="invBodyDashSearch" type="search" class="form-input" placeholder="Search stock" oninput="renderInvTable()" aria-label="Search stock">
          </div>
          <div class="filter-group"><label for="invBodyDashOrder">Sort</label>
            <select id="invBodyDashOrder" onchange="renderInvTable()" aria-label="Sort stock">
              <option value="highest">Most to least stock</option>
              <option value="lowest">Least to most stock</option>
              <option value="newest">New to Old</option>
              <option value="oldest">Old to New</option>
            </select>
          </div>
        </div>
        <div class="data-table-wrapper">
          <table class="clean">
            <thead>
              <tr>
                <th>Item</th>
                <th class="num">In Stock</th>
                <th class="num">Buy More When Below @include('partials.tip', [
                  'text' => 'Reorder point: the quantity at which you should buy more. Once stock falls to this number or lower the item is flagged, so you can reorder before you run out.',
                  'label' => 'What is the reorder point?',
                ])</th>
                <th class="num">Days Left @include('partials.tip', [
                  'text' => 'Days of stock cover: how many days the quantity you hold should last at your recent selling rate. "n/a" means no reorder point is set for this item yet, so no estimate can be made.',
                  'label' => 'What does the Lasts column mean?',
                ])</th>
                <th class="text-center">Stock</th>
              </tr>
            </thead>
            <tbody id="invBodyDash"></tbody>
          </table>
        </div>
        <button onclick="showSection('inv')" class="btn btn-tertiary btn-sm order-type-selector">Manage Stock &amp; Reordering</button>
      </div>

      <div class="card">
        <h3 class="panel-heading">Outstanding Customer Balances</h3>
        <div class="customer-balances-toolbar no-print">
          <div class="customer-search-group">
            <input id="topLiaBodySearch" type="search" class="form-input" placeholder="Search customer balances" oninput="renderLedger()" aria-label="Search customer balances">
          </div>
          <div class="customer-sort-group">
            <select id="topLiaBodyOrder" onchange="renderLedger()" aria-label="Sort customer balances">
              <option value="highest">Highest balance first</option>
              <option value="lowest">Least to highest balance</option>
              <option value="newest">New to Old</option>
              <option value="oldest">Old to New</option>
            </select>
          </div>
        </div>
        <div class="data-table-wrapper">
          <table class="clean">
            <thead>
              <tr>
                <th>Customer</th>
                <th class="num">Outstanding</th>
                <th class="text-right">Action</th>
              </tr>
            </thead>
            <tbody id="topLiaBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
  @include('partials.fulfillment')
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
      {{-- A <button> rather than a bare <a>: without an href an anchor is skipped
           by the tab order and exposes no role, so this was mouse-only. --}}
      <button type="button" class="btn btn-primary" data-action="send" onclick="sendWhatsApp()">Send via WhatsApp</button>
    </footer>
  </div>
</div>

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Overview'])
renderInvTable();
renderInsights();
renderDemandChart();
startPageRefresh(async () => { await loadFromServer(); renderInvTable(); renderInsights(); renderDemandChart(); renderSidebarBadges(); renderStamp(); });
@endcomponent
<script src="{{ asset('js/admin/dashboard-onboarding.js') }}?v={{ asset_version('js/admin/dashboard-onboarding.js') }}"></script>

@extends('layouts.admin')

@section('title', 'Dashboard | ' . ($stationName ?? 'AquaFlow'))
@section('meta-description', 'Sales, stock and demand forecast for ' . ($stationName ?? 'AquaFlow') . ' water refilling station.')
@section('topbar-title', 'Overview')


@section('admin-content')
  <section id="s-dash">
    {{-- Command centre order: what is waiting on a decision, then today's
         money, then next week's demand. Every element id used by insights.js,
         inventory.js and tests/frontend.cjs is kept (kRev, kRevSub, kRevTrend,
         kGal, kGalSub, invHealth, kStockSub, kStockAction, kLia, kLiaSub,
         kLiaTop, kpiGrid, dashChartRow, advisoryRail, advisory, chDemand). --}}
    <header class="page-head">
      <div class="page-head__text">
        <h2 class="page-head__title">{{ now()->timezone(config('app.timezone'))->format('l, j F') }}</h2>
        <p class="page-head__purpose">Decide what is waiting on you, check today's money, then plan next week's stock.</p>
      </div>
      <div class="page-head__actions">
        <a href="{{ url('/admin/orders') }}" class="btn btn-secondary">View active orders</a>
      </div>
    </header>

    <section class="overview-block" aria-labelledby="decisionTitle">
      <div class="overview-block__head">
        <h2 id="decisionTitle" class="overview-block__title">Needs a decision today</h2>
        <p id="decisionSummary" class="overview-block__note" role="status">Checking what is waiting&hellip;</p>
      </div>
      <div class="stat-strip" id="decisionStrip">
        <div class="stat" id="decApprovalsCell">
          <span class="stat__label">Approval requests</span>
          <span class="stat__value" id="decApprovals"><span class="skeleton skeleton--figure"></span></span>
          <span class="stat__sub" id="decApprovalsSub">Price, stock and account changes</span>
          <a class="stat__action" href="{{ url('/admin/approvals') }}">Review requests</a>
        </div>
        <div class="stat" id="decDefectsCell">
          <span class="stat__label">Defect reports</span>
          <span class="stat__value" id="decDefects"><span class="skeleton skeleton--figure"></span></span>
          <span class="stat__sub" id="decDefectsSub">Damaged stock to approve or reject</span>
          <a class="stat__action" href="{{ url('/admin/defects') }}">Review reports</a>
        </div>
        <div class="stat" id="decStockCell">
          <span class="stat__label">Items to reorder</span>
          <span class="stat__value" id="invHealth"><span class="skeleton skeleton--figure"></span></span>
          <span class="stat__sub" id="kStockSub">Stock levels are loading</span>
          <a class="stat__action" id="kStockAction" href="{{ url('/admin/inventory') }}">View stock</a>
        </div>
        <div class="stat" id="decRemitCell">
          <span class="stat__label">Rider cash to collect</span>
          <span class="stat__value" id="decRemit"><span class="skeleton skeleton--figure"></span></span>
          <span class="stat__sub" id="decRemitSub">Delivery cash not yet handed in</span>
          <a class="stat__action" href="{{ url('/admin/orders') }}">Open orders</a>
        </div>
      </div>
    </section>

    <section class="overview-block" aria-labelledby="moneyTitle">
      <div class="overview-block__head">
        <h2 id="moneyTitle" class="overview-block__title">Today's money</h2>
        <a class="overview-block__link" href="{{ url('/admin/sales') }}">Sales history</a>
      </div>
      {{-- Values start as skeletons rather than a literal "-": a dash reads as
           a real zero to an owner glancing at the screen. renderInsights()
           overwrites each element's textContent, which discards the skeleton. --}}
      <div class="stat-strip" id="kpiGrid" aria-busy="true">
        <div class="stat">
          <span class="stat__label">Sales Revenue Today</span>
          <span class="stat__value" id="kRev"><span class="skeleton skeleton--figure"></span></span>
          <span class="stat__sub stat__sub--trend"><span id="kRevSub"><span class="skeleton skeleton--text"></span></span>
            {{-- Trend lives outside #kRev because that element is replaced by textContent. --}}
            <span class="kpi-trend" id="kRevTrend" aria-hidden="true"></span></span>
        </div>
        <div class="stat">
          <span class="stat__label">Balance payments today</span>
          <span class="stat__value" id="kCollected"><span class="skeleton skeleton--figure"></span></span>
          <span class="stat__sub" id="kCollectedSub">Customer balance payments and rider cash; excludes cash sales</span>
        </div>
        <div class="stat">
          <span class="stat__label">Customers owe</span>
          <span class="stat__value" id="kLia"><span class="skeleton skeleton--figure"></span></span>
          <span class="stat__sub" id="kLiaSub"><span class="skeleton skeleton--text"></span></span>
          <span class="stat__sub" id="kLiaTop"></span>
          <a class="stat__action" href="{{ url('/admin/customers') }}">View balances</a>
        </div>
      </div>
    </section>

    @if(auth()->user()?->isSuperAdmin())
    <section class="card onboarding-checklist-card" id="onboardingChecklist">
      <div class="onboarding-card-head">
        <div>
          <h2 class="onboarding-card-title">Getting started</h2>
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
            <span class="onboarding-step-label">Stock &amp; Prices</span>
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
            <span class="onboarding-step-sub">Assign managers, cashiers, refillers and riders.</span>
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
            <span class="onboarding-step-sub">Open the counter workspace when you need to serve a customer.</span>
          </div>
        </a>
      </div>
    </section>

    @endif

    <div class="dash-row-chart" id="dashChartRow">
      <div class="card">
        <div class="forecast-head">
          <div>
            <h2 class="panel-heading">Expected sales next week</h2>
            <p class="auto-deduct-caption">The last 30 days of refill sales against the upcoming forecast.</p>
          </div>
          <div class="forecast-head__figure">
            <span class="stat__value" id="kGal"><span class="skeleton skeleton--figure"></span></span>
            <span class="stat__sub" id="kGalSub"><span class="skeleton skeleton--text"></span></span>
          </div>
        </div>
        {{-- Canvas is invisible to assistive tech without a name. The figures
             behind it are already rendered as text in #insDemand below, so the
             label points there rather than restating them. --}}
        <canvas id="chDemand" height="120" role="img"
                aria-label="Demand outlook chart: 30 days of refill sales against the next 7 days of forecast. Sales trends and averages are listed below under How Sales Are Going."></canvas>
        <div id="fcMetaDash" class="orders-queue-list order-type-selector"></div>
        <h3 class="panel-heading">How sales are going</h3>
        <div id="insDemand"></div>
        {{-- Tertiary: this is a deep jump into analytics, not a committed
             action. It must not compete visually with "Make a Purchase Request". --}}
        <button onclick="showSection('arima')" class="btn btn-tertiary btn-sm order-type-selector">Open Demand Forecast</button>
      </div>
      {{-- The forecast sets this row's height; the advisory list scrolls within it. --}}
      <div class="card card--rail" id="advisoryRail">
        <h2 class="panel-heading">Things to buy</h2>
        <div id="advisory" class="overview-advisories" tabindex="0" role="region" aria-label="Things to buy"></div>
        <button type="button" onclick="draftPO()" class="btn btn-secondary btn-sm overview-purchase">Make a Purchase Request</button>
      </div>
    </div>

    {{-- 3. REFERENCE. The tables behind the cards above. --}}
    <div class="dashboard-row-2col overview-reference">
      <div class="card">
        <h2 class="panel-heading">Jug stock &amp; refill supplies</h2>
        <p class="auto-deduct-caption">New Slim Jugs: <strong id="ledgerSlim">Loading</strong> &middot; New Round Jugs: <strong id="ledgerRound">Loading</strong></p>
        <div class="filter-toolbar table-filters stock-filters no-print">
          <div id="invFilterTabs" class="filter-group"></div>
          <div class="filter-group"><label for="invBodyDashSearch">Search</label>
            <input id="invBodyDashSearch" type="search" class="form-input" placeholder="Search item names" oninput="renderInvTable()" aria-label="Search stock by item name">
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
        <h2 class="panel-heading">Outstanding Customer Balances</h2>
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

    {{-- The sidebar holds these same links on wide screens, so this card is
         only shown below 1025px, where the sidebar is collapsed. --}}
    @if(auth()->user()?->isSuperAdmin())
    <nav class="owner-controls card" aria-label="Business controls">
      <div><h2>Business controls</h2><p>Manage access, selling prices and your station settings.</p></div>
      <div class="owner-control-links">
        <a class="btn btn-secondary" href="{{ url('/admin/users') }}">Team &amp; access</a>
        <a class="btn btn-secondary" href="{{ url('/admin/inventory?view=prices') }}">Catalog &amp; prices</a>
        <a class="btn btn-secondary" href="{{ url('/admin/settings') }}">Station settings</a>
      </div>
    </nav>
    @endif
  </section>
  @include('partials.fulfillment', ['monitor' => true])
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
refreshOverviewDecisions();
startPageRefresh(async () => { await loadFromServer(); renderInvTable(); renderInsights(); renderDemandChart(); renderSidebarBadges(); renderStamp(); refreshOverviewDecisions(); });
@endcomponent
<script src="{{ asset('js/admin/dashboard-onboarding.js') }}?v={{ asset_version('js/admin/dashboard-onboarding.js') }}"></script>

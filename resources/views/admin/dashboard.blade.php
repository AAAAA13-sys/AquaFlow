@extends('layouts.admin')

@section('title', 'Dashboard | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Station Dashboard & Analytics')

@section('admin-content')
  <section id="s-dash">

    {{-- 1. ACTION FIRST. The owner should not scroll past charts and tables to
         reach the work list. Everything below is evidence for these three cards. --}}
    <div class="card dashboard-todo-first">
      <h3 class="panel-heading">What You Need To Do Today</h3>
      <p class="auto-deduct-caption">Ranked by how urgent they are. Each one links to the details behind it.</p>
      <div id="insActions" class="custody-summary-box"></div>
    </div>

    <div class="kpi-cards-grid">
      <div class="kpi-card-body kpi-blue">
        <p class="kpi-label">Money Taken Today</p>
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
        <p class="kpi-subtext">running low — order soon</p>
      </div>
      <div class="kpi-card-body kpi-yellow">
        <p class="kpi-label">Customers Who Owe Money</p>
        <p class="kpi-value" id="kLia">-</p>
        <p class="kpi-subtext" id="kLiaSub">-</p>
        <p class="kpi-micro" id="kLiaTop"></p>
      </div>
    </div>

    <div class="dash-row-chart">
      <div class="card">
        <h3 class="panel-heading">What You Will Need <span class="term-hint">ARIMA forecast</span></h3>
        <p class="auto-deduct-caption">Daily refill gallon projections from your sales history.</p>
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
        <div id="advisory" class="custody-summary-box"></div>
      </div>
    </div>

    {{-- 2. INTERPRETATION. Stock Levels now sits directly beneath the
         advisories rail it belongs to, so both are read as one unit. --}}
    <div class="dashboard-row-3col">
      <div class="card">
        <h3 class="panel-heading">What the Trend Tells You</h3>
        <div id="insDemand"></div>
      </div>
      <div class="card">
        <h3 class="panel-heading">Stock Levels: Days Left</h3>
        <p class="auto-deduct-caption">Every consumable, soonest first. Order quantities live in Restock Advisories.</p>
        <div id="insRunway"></div>
      </div>
      <div class="card">
        <h3 class="panel-heading">Who to Visit First</h3>
        <div id="insCollect"></div>
      </div>
    </div>

    {{-- 3. REFERENCE. The tables behind the cards above. --}}
    <div class="dashboard-row-2col">
      <div class="card">
        <h3 class="panel-heading">Stock Check <span class="term-hint">reorder points</span></h3>
        <div id="invFilterTabs" class="filter-tabs no-print" role="group" aria-label="Filter stock by type"></div>
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

{{-- Reminder drawer. Opens from the Custody Ledger's Remind action with the
     exact message template pre-filled and editable by the owner. --}}
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
      <p class="af-drawer-hint">Edit the text here before sending if you need to.</p>
    </div>

    <footer class="af-drawer-foot">
      <button type="button" class="btn btn-secondary" data-action="copy" onclick="copyReminder()">Copy</button>
      <a class="btn btn-primary" data-action="send" target="_blank" rel="noopener">Send via WhatsApp</a>
    </footer>
  </div>
</div>

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

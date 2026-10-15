@extends('layouts.admin')

@section('title', 'Stock & Prices | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Stock & Prices')

@section('admin-content')
  <section id="s-inv" class="admin-page">
    <div class="admin-page-titlebar">
    @include('partials.admin-page-header', ['eyebrow' => 'STOCK CONTROL', 'heading' => 'Stock & Prices', 'description' => 'Track available supplies, reorder levels, and stock movements - and choose what the cashier sells and what it costs.'])
    </div>

    {{-- Supplies and Till are one screen: a till item is a price plus a recipe that
         draws down one of the supply rows below, so splitting them let the two drift. --}}
    <div class="stock-viewbar">
      <div class="stage-segment" role="group" aria-label="Stock view">
        <button type="button" id="stockViewSupplies" class="seg-btn" onclick="showStockView('supplies')">Supplies</button>
        <button type="button" id="stockViewPrices" class="seg-btn" onclick="showStockView('prices')">Till &amp; Prices</button>
      </div>
      <button type="button" id="stockAddButton" class="btn btn-primary form-card" aria-expanded="false" aria-controls="invFormPanel" onclick="toggleAddForm(this)">Add consumable</button>
    </div>

    <div id="stockViewSuppliesPanel">
      <div id="inventorySummary" class="admin-summary" aria-label="inventory summary"></div>
        <div id="invFormPanel" class="card form-card hidden">
      <h3 class="panel-heading">Add consumable</h3>
      <form id="invForm" onsubmit="return createInventoryItem(event)" class="admin-entry-form">
        <div class="admin-entry-field"><label class="form-label" for="invName">Item name</label><input id="invName" class="form-input" placeholder="Item name *" required maxlength="150" aria-label="Item name"></div>
        <div class="admin-entry-field"><label class="form-label" for="invCat">Category</label><select id="invCat" class="form-input" aria-label="Category">
          <option value="Consumable">Consumable</option>
          <option value="Filtration">Filtration</option>
          <option value="Cleaning">Cleaning</option>
          <option value="Asset">Asset</option>
        </select></div>
        <div class="admin-entry-field"><label class="form-label" for="invOn">Opening quantity</label><input id="invOn" type="number" class="form-input" placeholder="On-hand" min="0" value="0" aria-label="Opening stock"></div>
        <div class="admin-entry-field"><label class="form-label" for="invUnit">Stock unit</label><input id="invUnit" class="form-input" placeholder="Unit" value="pcs" maxlength="20" aria-label="Unit"></div>
        <div class="admin-entry-field"><label class="form-label" for="invLead">Restock time (days)</label><input id="invLead" type="number" class="form-input" placeholder="Lead" min="1" max="14" value="2" aria-label="Restock days"></div>
        <div class="admin-entry-field"><label class="form-label" for="invSupplier">Supplier</label><select id="invSupplier" class="form-input" aria-label="Supplier"><option value="">No supplier</option></select></div>
        <button type="submit" class="btn btn-primary btn-sm">Add</button>
      </form>
    </div>

    <div class="filter-toolbar no-print table-filters stock-filters">
      <div class="filter-group"><label for="invType">Type</label><select id="invType" onchange="renderInvTable()"><option value="">All types</option><option>Consumable</option><option>Filtration</option><option>Cleaning</option><option>Asset</option></select></div>
      <div class="filter-group"><label for="invBodySearch">Search</label><input id="invBodySearch" type="search" class="form-input" placeholder="Search stock" oninput="renderInvTable()"></div>
      <div class="filter-group"><label for="invBodyOrder">Sort</label><select id="invBodyOrder" onchange="renderInvTable()"><option value="lowest">Least to most stock</option><option value="highest">Most to least stock</option></select></div>
    </div>

    <div class="card data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>Item</th>
            <th>Type</th>
            <th class="num">In Stock <span class="term-hint">(on-hand)</span></th>
            <th class="num">Safety stock @include('partials.tip', [
              'text' => 'Safety stock: the level you try never to drop below while waiting for a delivery. Crossing it means you are already late, so this is the more serious of the two thresholds.',
              'label' => 'What is safety stock?',
            ])</th>
            <th class="num">Reorder Below @include('partials.tip', [
              'text' => 'Reorder point: the quantity at which you should place a new order. Set it above your safety stock so you have time to order before stock gets critical.',
              'label' => 'What is the reorder point?',
            ])</th>
            <th class="text-center">Restock time <span class="term-hint">(days)</span> @include('partials.tip', [
              'text' => 'How many days your supplier normally takes to deliver. The app subtracts this from your days-of-cover so it warns you in time to still receive the order.',
              'label' => 'What does restock time mean?',
            ])</th>
            <th class="text-center">Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody id="invBody"></tbody>
      </table>
    </div>
    </div>

    <div id="stockViewPricesPanel" class="hidden">
      <div id="tillSummary" class="admin-summary" aria-label="till summary"></div>

      <div id="tillFormPanel" class="card form-card hidden">
        <h3 class="panel-heading">Add an item for sale</h3>
        <p class="cell-sub">It appears on the cashier terminal straight away. The consumption recipe is worked out from the category - there is nothing else to configure.</p>
        <form id="tillForm" onsubmit="return createTillItem(event)" class="admin-entry-form">
          <div class="admin-entry-field">
            <label class="form-label" for="tillName">Item name</label>
            <input id="tillName" class="form-input field-min-160" placeholder="e.g. Plastic Faucet" required maxlength="100">
          </div>
          <div class="admin-entry-field">
            <label class="form-label" for="tillPrice">Price (VAT inclusive)</label>
            <input id="tillPrice" type="number" step="0.01" min="0.01" class="form-input" placeholder="0.00" required>
          </div>
          <div class="admin-entry-field">
            <label class="form-label" for="tillCategory">Type</label>
            <select id="tillCategory" class="form-input" onchange="onTillCategoryChange()">
              <option value="refill">Refill (customer's own jug)</option>
              <option value="container">New jug (for sale)</option>
              <option value="consumable">Supply</option>
              <option value="cleaning">Cleaning supply</option>
            </select>
          </div>
          <div class="admin-entry-field">
            <label class="form-label" for="tillKind">Jug shape</label>
            <select id="tillKind" class="form-input">
              <option value="S">Slim</option>
              <option value="R">Round</option>
              <option value="X">Not a jug</option>
            </select>
          </div>
          <div class="admin-entry-field">
            <label class="form-label" for="tillStock">Draws down stock</label>
            <select id="tillStock" class="form-input"></select>
          </div>
          <div class="admin-entry-field">
            <label class="form-label" for="tillUnits">Stock used per sale</label>
            <input id="tillUnits" type="number" min="1" max="1000" value="1" class="form-input">
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Add to till</button>
        </form>
      </div>

      <div class="admin-summary" id="tillLegend" aria-label="till legend">
        <article><span>On the till</span><strong id="tillOnCount">0</strong><small>Buttons the cashier sees</small></article>
        <article><span>Hidden</span><strong id="tillOffCount">0</strong><small>Kept for records, not sold</small></article>
        <article><span>Walk-in only</span><strong id="tillWalkInCount">0</strong><small>Cannot be put on a delivery</small></article>
      </div>

      {{-- No sort control: this list must read in the order the cashier sees. --}}
      @include('partials.table-filters', ['target' => 'tillGrid', 'label' => 'till items', 'refresh' => 'renderTill()', 'noSort' => true])

      <div class="till-grid" id="tillGrid"></div>
    </div>
  </section>

  @include('partials.editor-drawer', ['drawerId' => 'invEditWrap', 'bodyId' => 'invEditBody', 'title' => 'Edit item', 'closeAction' => 'closeInventoryEditor()'])
  @include('partials.editor-drawer', ['drawerId' => 'stockDetailWrap', 'bodyId' => 'stockDetailBody', 'title' => 'Stock movements', 'closeAction' => 'closeStockDetail()'])
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Stock & Prices'])
fillInventorySupplierOptions();
renderInvTable();
showStockView(new URLSearchParams(window.location.search).get('view') === 'prices' ? 'prices' : 'supplies');
startPageRefresh(async () => { await loadFromServer(); renderInvTable(); renderSidebarBadges(); renderStamp(); });
@endcomponent

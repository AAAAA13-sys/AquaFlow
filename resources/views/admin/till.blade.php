@extends('layouts.admin')

@section('title', 'Till & Prices | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Till & Prices')

@section('admin-content')
  <section id="s-till" class="admin-page">
    <div class="admin-page-titlebar">
      @include('partials.admin-page-header', [
        'eyebrow' => 'POINT OF SALE',
        'heading' => 'Till & Prices',
        'description' => 'Choose which buttons appear on the cashier terminal and what they cost. Prices are VAT inclusive and changing one never alters a past receipt.',
      ])
      <button type="button" class="btn btn-primary form-card" aria-expanded="false" aria-controls="tillFormPanel" onclick="toggleAddForm(this)">Add item for sale</button>
    </div>

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
  </section>
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Till & Prices'])
renderTill();
startPageRefresh(async () => { await loadFromServer(); renderTill(); renderSidebarBadges(); renderStamp(); });
@endcomponent

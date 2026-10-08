@extends('layouts.admin')

@section('title', 'Stock & Supplies | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Stock & Supplies')

@section('admin-content')
  <section id="s-inv" class="admin-page">
    <div class="admin-page-titlebar">
    @include('partials.admin-page-header', ['eyebrow' => 'STOCK CONTROL', 'heading' => 'Stock & Supplies', 'description' => 'Track available supplies, reorder levels, and stock movements.'])
      <button type="button" class="btn btn-primary form-card" aria-expanded="false" aria-controls="invFormPanel" onclick="toggleAddForm(this)">Add consumable</button>
    </div>


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

    @include('partials.table-filters', ['target' => 'invBody', 'label' => 'stock', 'refresh' => "renderInvTable()"])

    <div class="card data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>Item</th>
            <th>Type</th>
            <th class="num">In Stock <span class="term-hint">on-hand</span></th>
            <th class="num">Minimum <span class="term-hint">safety stock</span>@include('partials.tip', [
              'text' => 'Safety stock: the level you try never to drop below while waiting for a delivery. Crossing it means you are already late, so this is the more serious of the two thresholds.',
              'label' => 'What is safety stock?',
            ])</th>
            <th class="num">Reorder When Below <span class="term-hint">ROP</span>@include('partials.tip', [
              'text' => 'Reorder point: the quantity at which you should place a new order. Set it above your safety stock so you have time to order before stock gets critical.',
              'label' => 'What is ROP, the reorder point?',
            ])</th>
            <th>Restock time <span class="term-hint">days</span>@include('partials.tip', [
              'text' => 'How many days your supplier normally takes to deliver. The app subtracts this from your days-of-cover so it warns you in time to still receive the order.',
              'label' => 'What does restock time mean?',
            ])</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="invBody"></tbody>
      </table>
    </div>
  </section>

  @include('partials.editor-drawer', ['drawerId' => 'invEditWrap', 'bodyId' => 'invEditBody', 'title' => 'Edit item', 'closeAction' => 'closeInventoryEditor()'])
  @include('partials.editor-drawer', ['drawerId' => 'stockDetailWrap', 'bodyId' => 'stockDetailBody', 'title' => 'Stock movements', 'closeAction' => 'closeStockDetail()'])
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Stock & Supplies'])
fillInventorySupplierOptions();
renderInvTable();
@endcomponent

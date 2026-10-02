@extends('layouts.admin')

@section('title', 'Consumables & ROP | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Consumables & ROP')

@section('admin-content')
  <section id="s-inv">
    <h2 class="auth-title">Consumables &amp; ROP</h2>
    <p class="auth-description">Dynamic reorder point thresholds calculate the precise stock level to initiate supplier replenishment.</p>

    <div class="card" style="margin-bottom:1rem;">
      <h3 class="panel-heading">Add consumable</h3>
      <form id="invForm" onsubmit="return createInventoryItem(event)" class="filter-toolbar" style="flex-wrap:wrap;">
        <input id="invName" class="form-input" placeholder="Item name *" required maxlength="150" style="min-width:180px;">
        <select id="invCat" class="form-input">
          <option value="Consumable">Consumable</option>
          <option value="Filtration">Filtration</option>
          <option value="Cleaning">Cleaning</option>
          <option value="Asset">Asset</option>
        </select>
        <input id="invOn" type="number" class="form-input" placeholder="On-hand" min="0" value="0" style="width:100px;">
        <input id="invUnit" class="form-input" placeholder="Unit" value="pcs" maxlength="20" style="width:90px;">
        <input id="invLead" type="number" class="form-input" placeholder="Lead" min="1" max="14" value="2" style="width:90px;">
        <select id="invSupplier" class="form-input"><option value="">No supplier</option></select>
        <button type="submit" class="btn btn-primary btn-sm">Add</button>
      </form>
    </div>

    <div class="card data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>Item</th>
            <th>Category</th>
            <th class="num">On-hand</th>
            <th class="num">Safety</th>
            <th class="num">Reorder at</th>
            <th>Restock (days)</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="invBody"></tbody>
      </table>
    </div>
  </section>

  <div id="invEditWrap" class="side-drawer hidden">
    <div class="flex-between order-type-selector">
      <h3 class="panel-heading">Edit item</h3>
      <button onclick="closeInventoryEditor()" class="btn btn-ghost btn-sm">Close</button>
    </div>
    <div id="invEditBody"></div>
  </div>
@endsection

@push('scripts')
  <script>
    (async () => {
      window.SESSION = await bootAdminPortal('Consumables & ROP');
      if (SESSION) {
        fillInventorySupplierOptions();
        renderInvTable();
      }
    })();
  </script>
@endpush

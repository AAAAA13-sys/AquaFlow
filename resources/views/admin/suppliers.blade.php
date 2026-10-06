@extends('layouts.admin')

@section('title', 'Suppliers | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Suppliers')

@section('admin-content')
  <section id="s-sup">
    <h2 class="auth-title">Suppliers</h2>
    <p class="auth-description">Restock times on this page feed the reorder alerts in Consumables &amp; ROP.</p>

    <div class="card" style="margin-bottom:1rem;">
      <h3 class="panel-heading">Add supplier</h3>
      <form id="supForm" onsubmit="return createSupplier(event)" class="filter-toolbar" style="flex-wrap:wrap;">
        <input id="supName" class="form-input" placeholder="Supplier name *" required maxlength="120" style="min-width:160px;">
        <input id="supItems" class="form-input" placeholder="Supplied items *" required maxlength="255" style="min-width:160px;">
        <input id="supLead" type="number" class="form-input" placeholder="Lead (days)" min="1" max="14" value="2" style="width:110px;">
        <input id="supContact" class="form-input" placeholder="Contact" maxlength="50" style="min-width:140px;">
        <input id="supLast" type="date" class="form-input">
        <button type="submit" class="btn btn-primary btn-sm">Add</button>
      </form>
    </div>

    <div class="suppliers-grid" id="supGrid"></div>
  </section>

  <div id="supEditWrap" class="side-drawer hidden">
    <div class="flex-between order-type-selector">
      <h3 class="panel-heading">Edit supplier</h3>
      <button onclick="closeSupplierEditor()" class="btn btn-ghost btn-sm">Close</button>
    </div>
    <div id="supEditBody"></div>
  </div>
@endsection

@push('scripts')
  <script>
    (async () => {
      window.SESSION = await bootAdminPortal('Suppliers');
      if (SESSION) {
        renderSup();
      }
    })();
  </script>
@endpush

@extends('layouts.admin')

@section('title', 'Suppliers | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Suppliers')

@section('admin-content')
  <section id="s-sup" class="admin-page">
    <div class="admin-page-titlebar">
    @include('partials.admin-page-header', ['eyebrow' => 'PURCHASING', 'heading' => 'Suppliers', 'description' => 'Keep supplier contacts and delivery times ready for your next restock.'])
      <button type="button" class="btn btn-primary form-card" aria-expanded="false" aria-controls="supFormPanel" onclick="toggleAddForm(this)">Add supplier</button>
    </div>


<div id="supplierSummary" class="admin-summary" aria-label="suppliers summary"></div>
        <div id="supFormPanel" class="card form-card hidden">
      <h3 class="panel-heading">Add supplier</h3>
      <form id="supForm" onsubmit="return createSupplier(event)" class="admin-entry-form">
        <div class="admin-entry-field"><label class="form-label" for="supName">Supplier name</label><input id="supName" class="form-input field-min-160" placeholder="Supplier name *" required maxlength="120" aria-label="Supplier name"></div>
        <div class="admin-entry-field"><label class="form-label" for="supItems">Supplied items</label><input id="supItems" class="form-input field-min-160" placeholder="Supplied items *" required maxlength="255" aria-label="Supplied items"></div>
        <div class="admin-entry-field"><label class="form-label" for="supLead">Delivery time (days)</label><input id="supLead" type="number" class="form-input" placeholder="Lead (days)" min="1" max="14" value="2" aria-label="Delivery days"></div>
        <div class="admin-entry-field"><label class="form-label" for="supContact">Contact number</label><input id="supContact" class="form-input field-min-140" placeholder="Contact" maxlength="50" aria-label="Contact"></div>
        <div class="admin-entry-field"><label class="form-label" for="supLast">Last delivery date</label><input id="supLast" type="date" class="form-input" aria-label="Last delivery"></div>
        <button type="submit" class="btn btn-primary btn-sm">Add</button>
      </form>
    </div>

    @include('partials.table-filters', ['target' => 'supGrid', 'label' => 'suppliers', 'refresh' => "renderSup()"])

    <div class="suppliers-grid" id="supGrid"></div>
  </section>

  @include('partials.editor-drawer', ['drawerId' => 'supEditWrap', 'bodyId' => 'supEditBody', 'title' => 'Edit supplier', 'closeAction' => 'closeSupplierEditor()'])
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Suppliers'])
renderSup();
@endcomponent

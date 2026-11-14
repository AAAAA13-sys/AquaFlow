@extends('layouts.admin')

@section('title', 'Purchase Orders | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Purchase Orders')

@section('page-actions')
<div class="page-head__actions">
        <button type="button" id="poSuggestBtn" class="btn btn-secondary" onclick="PO.openSuggestions()">Start from reorder suggestions</button>
        <button type="button" id="poNewBtn" class="btn btn-primary" onclick="PO.openCreate()">New purchase order</button>
      </div>
@endsection

@section('admin-content')
  <section id="s-po" class="admin-page po-page">
    <header class="page-head">
      <div class="page-head__text">
        <h2 class="page-head__title">Purchase orders</h2>
        <p class="page-head__purpose">Order supplies from your suppliers and record what arrives.</p>
      </div>

    </header>

    <div id="poStats" class="status-line" aria-label="Purchase order summary"></div>

    <div class="filter-tabs po-tabs" id="poTabs" role="group" aria-label="Filter purchase orders by status"></div>

    <div id="poStatus" class="visually-hidden" role="status" aria-live="polite"></div>
    <div id="poList" class="card data-table-wrapper" aria-busy="true"></div>
  </section>

  @include('partials.editor-drawer', ['drawerId' => 'poDrawer', 'bodyId' => 'poBody', 'title' => 'Purchase order', 'closeAction' => 'PO.closeDrawer()'])
@endsection

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/erp.css') }}?v={{ asset_version('css/erp.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('js/admin/purchasing.js') }}?v={{ asset_version('js/admin/purchasing.js') }}"></script>
@endpush

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Purchase Orders'])
PO.init();
startPageRefresh(async () => { await PO.refresh(); });
@endcomponent

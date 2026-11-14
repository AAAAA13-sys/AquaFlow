@extends('layouts.admin')

@section('title', 'Expenses | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Expenses')

@section('page-actions')
<div class="page-head__actions">
        <button type="button" id="expExportBtn" class="btn btn-secondary" onclick="EXP.exportCsv()" disabled>Export CSV</button>
        <button type="button" id="expNewBtn" class="btn btn-primary" onclick="EXP.toggleForm()" aria-expanded="false" aria-controls="expFormCard">Record expense</button>
      </div>
@endsection

@section('admin-content')
  <section id="s-exp" class="admin-page exp-page">
    <header class="page-head">
      <div class="page-head__text">
        <h2 class="page-head__title">Expenses</h2>
        <p class="page-head__purpose">Record what the station spends on rent, power, wages and repairs.</p>
      </div>

    </header>

    <div id="expFormCard" class="card exp-form-card" hidden></div>

    <div class="filter-bar exp-filters" role="group" aria-label="Filter expenses">
      <div>
        <label for="expFrom">From</label>
        <input id="expFrom" type="date" class="form-input">
      </div>
      <div>
        <label for="expTo">To</label>
        <input id="expTo" type="date" class="form-input">
      </div>
      <div>
        <label for="expCategory">Category</label>
        <select id="expCategory" class="form-input"></select>
      </div>
      <div class="filter-bar__actions">
        <button type="button" id="expApplyBtn" class="btn btn-secondary btn-sm">Apply</button>
      </div>
    </div>

    <div id="expStats" class="stat-strip" aria-label="Expense summary"></div>

    <div id="expStatus" class="visually-hidden" role="status" aria-live="polite"></div>
    <div id="expVoidCard" class="card exp-void-card" hidden></div>
    <div id="expList" class="card data-table-wrapper" aria-busy="true"></div>
  </section>
@endsection

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/erp.css') }}?v={{ asset_version('css/erp.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('js/admin/expenses.js') }}?v={{ asset_version('js/admin/expenses.js') }}"></script>
@endpush

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Expenses'])
EXP.init();
startPageRefresh(async () => { await EXP.refresh(); });
@endcomponent

@extends('layouts.admin')

@section('title', 'Reports | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Reports')

@section('page-actions')
<div class="page-head__actions">
        <button type="button" id="repExportBtn" class="btn btn-secondary" onclick="REP.exportCsv()" disabled>Export CSV</button>
        <button type="button" id="repPrintBtn" class="btn btn-primary" onclick="REP.print()" disabled>Print</button>
      </div>
@endsection

@section('admin-content')
  <section id="s-rep" class="admin-page rep-page">
    <header class="page-head no-print">
      <div class="page-head__text">
        <h2 class="page-head__title">Reports</h2>
        <p class="page-head__purpose">Sales, money collected, costs and profit for any period up to a year.</p>
      </div>

    </header>

    <div class="filter-bar rep-filters no-print" role="group" aria-label="Report period">
      <div class="is-wide rep-presets-cell">
        <span class="rep-label" id="repPresetsLabel">Period</span>
        <div id="repPresets" class="rep-presets" role="radiogroup" aria-labelledby="repPresetsLabel">
          <button type="button" class="rep-preset" role="radio" aria-checked="false" data-preset="today">Today</button>
          <button type="button" class="rep-preset" role="radio" aria-checked="false" data-preset="week">This week</button>
          <button type="button" class="rep-preset" role="radio" aria-checked="true" data-preset="month">This month</button>
          <button type="button" class="rep-preset" role="radio" aria-checked="false" data-preset="last-month">Last month</button>
          <button type="button" class="rep-preset" role="radio" aria-checked="false" data-preset="custom">Custom</button>
        </div>
      </div>
      <div>
        <label for="repFrom">From</label>
        <input id="repFrom" type="date" class="form-input">
      </div>
      <div>
        <label for="repTo">To</label>
        <input id="repTo" type="date" class="form-input">
      </div>
      <div class="filter-bar__actions">
        <button type="button" id="repApplyBtn" class="btn btn-secondary btn-sm">Apply</button>
      </div>
    </div>
    <p id="repRangeMsg" class="rep-range-msg no-print" role="alert" hidden></p>

    <div id="repStats" class="stat-strip no-print" aria-label="Period summary"></div>

    <div id="repCostNote" class="rep-note" role="note" hidden></div>
    <div id="repStatus" class="visually-hidden" role="status" aria-live="polite"></div>

    <article id="repSheet" class="sheet rep-sheet" aria-busy="true" aria-labelledby="repSheetTitle">
      <header class="sheet__head">
        <div>
          <p class="sheet__station">{{ $stationName ?? 'AquaFlow' }}</p>
          <h2 class="sheet__title" id="repSheetTitle">Sales &amp; profit report</h2>
        </div>
        <dl class="sheet__meta">
          <dt>Period</dt><dd id="repMetaPeriod">—</dd>
          <dt>Generated</dt><dd id="repMetaGenerated">—</dd>
        </dl>
      </header>
      <div id="repBody" class="rep-body"></div>
      <p class="sheet__foot">Amounts in pesos. Prices include 12% VAT; net sales exclude it. Voided sales and debt payments are not revenue. Dates follow station time.</p>
    </article>
  </section>
@endsection

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/erp-reports.css') }}?v={{ asset_version('css/erp-reports.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('js/admin/reports.js') }}?v={{ asset_version('js/admin/reports.js') }}"></script>
@endpush

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Reports'])
REP.init();
startPageRefresh(async () => { await REP.refresh(); });
@endcomponent

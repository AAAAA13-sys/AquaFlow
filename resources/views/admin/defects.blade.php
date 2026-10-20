@extends('layouts.admin')

@section('title', 'Defect Reports | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Defect Reports')

@section('admin-content')
  <section id="s-defects" class="admin-page">
    @include('partials.admin-page-header', ['eyebrow' => 'STOCK CONTROL', 'heading' => 'Defect Reports', 'description' => 'Staff report leaking jugs, torn seals and broken caps. Approve to write the units off stock, or reject if the report is not a genuine loss.'])

    <div id="defectSummary" class="admin-summary" aria-label="defect report summary"></div>

    <div class="filter-toolbar no-print table-filters">
      <div class="filter-group">
        <label class="form-label" for="defectStatus">Status</label>
        <select id="defectStatus" onchange="renderDefectTable()">
          <option value="">All reports</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
        </select>
      </div>
      @include('partials.table-filters', ['target' => 'defectBody', 'label' => 'defect reports', 'refresh' => "renderDefectTable()", 'embedded' => true])
    </div>

    <div class="card data-table-wrapper">
      <table class="clean">
        <caption class="visually-hidden">Defect and damaged-stock reports filed by station staff.</caption>
        <thead>
          <tr>
            <th>Reported by</th>
            <th>Item</th>
            <th class="num">Qty</th>
            <th>Reason</th>
            <th>Status</th>
            <th>Reported</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody id="defectBody"></tbody>
      </table>
    </div>
  </section>
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Defect Reports'])
loadDefectReports();
startPageRefresh(loadDefectReports);
@endcomponent

@push('scripts')
<script src="{{ asset('js/admin/defects.js') }}?v={{ asset_version('js/admin/defects.js') }}"></script>
@endpush

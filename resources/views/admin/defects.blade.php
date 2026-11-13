@extends('layouts.admin')

@section('title', 'Defect Reports | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Defect Reports')

@section('page-actions')
<div class="page-head__actions">
        <button type="button" class="btn btn-secondary" onclick="loadDefectReports()">Refresh</button>
      </div>
@endsection

@section('admin-content')
  <section id="s-defects" class="admin-page">
    <header class="page-head">
      <div class="page-head__text">
        <h2 class="page-head__title">Defect reports</h2>
        <p class="page-head__purpose">Approve damaged stock your staff reported so it comes off the count, or reject it if it is still usable.</p>
      </div>

    </header>

    <div id="defectSummary" class="status-line" aria-label="defect report summary"></div>

    <div class="filter-bar no-print" role="group" aria-label="Filter defect reports">
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
        <tbody id="defectBody"><tr><td colspan="7"><span class="skeleton skeleton--block" aria-hidden="true"></span><span class="visually-hidden">Loading defect reports</span></td></tr></tbody>
      </table>
    </div>
  </section>
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Defect Reports'])
loadDefectReports();
startPageRefresh(loadDefectReports);
@endcomponent

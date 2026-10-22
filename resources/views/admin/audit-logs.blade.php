@extends('layouts.admin')

@section('title', 'Audit Log | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Audit Log')

@section('admin-content')
  <section id="s-audit" class="admin-page">
    <div class="admin-page-titlebar">
      @include('partials.admin-page-header', ['eyebrow' => 'ACCOUNTABILITY', 'heading' => 'Audit Log', 'description' => 'Chronological audit trail of changes to prices, stock, suppliers, and collections.'])
    </div>

    <div class="card">
      <div class="audit-filters">
        <div class="admin-entry-field">
          <label class="form-label" for="auditEntity">Area</label>
          <select id="auditEntity" class="form-input" onchange="loadAuditLogs()">
            <option value="">Every area</option>
            <option value="product">Till items</option>
            <option value="inventoryitem">Stock</option>
            <option value="supplier">Suppliers</option>
            <option value="customer">Customers</option>
          </select>
        </div>
        <div class="admin-entry-field">
          <label class="form-label" for="auditAction">Action</label>
          <select id="auditAction" class="form-input" onchange="loadAuditLogs()">
            <option value="">Every action</option>
            <option value="create">Created</option>
            <option value="update">Changed</option>
            <option value="delete">Removed</option>
          </select>
        </div>
        <div class="admin-entry-field">
          <label class="form-label" for="auditFrom">From</label>
          <input id="auditFrom" type="date" class="form-input" onchange="loadAuditLogs()">
        </div>
        <div class="admin-entry-field">
          <label class="form-label" for="auditTo">To</label>
          <input id="auditTo" type="date" class="form-input" onchange="loadAuditLogs()">
        </div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="loadAuditLogs()">Refresh</button>
      </div>
    </div>

    <div id="auditList" class="audit-list" aria-live="polite"></div>
  </section>
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Audit Log'])
loadAuditLogs();
startPageRefresh(async () => { await loadAuditLogs(); renderStamp(); });
@endcomponent

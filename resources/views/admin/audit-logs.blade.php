@extends('layouts.admin')

@section('title', 'Audit Log | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Audit Log')

@section('page-actions')
<div class="page-head__actions">
        <button type="button" class="btn btn-secondary" onclick="loadAuditLogs()">Refresh</button>
      </div>
@endsection

@section('admin-content')
  <section id="s-audit" class="admin-page">
    <header class="page-head">
      <div class="page-head__text">
        <h2 class="page-head__title">Audit log</h2>
        <p class="page-head__purpose">Who changed prices, stock, suppliers and customer balances, and when.</p>
      </div>

    </header>

    <div class="filter-bar audit-filter-bar" role="group" aria-label="Filter the audit log">
      <div>
        <label class="form-label" for="auditEntity">Area</label>
        <select id="auditEntity" class="form-input" onchange="loadAuditLogs()">
          <option value="">Every area</option>
          <option value="product">Till items</option>
          <option value="inventoryitem">Stock</option>
          <option value="supplier">Suppliers</option>
          <option value="customer">Customers</option>
          <option value="purchaseorder">Purchase orders</option>
          <option value="expense">Expenses</option>
        </select>
      </div>
      <div>
        <label class="form-label" for="auditAction">Action</label>
        <select id="auditAction" class="form-input" onchange="loadAuditLogs()">
          <option value="">Every action</option>
          <option value="create">Created</option>
          <option value="update">Changed</option>
          <option value="delete">Removed</option>
          <option value="po_create">Purchase order drafted</option>
          <option value="po_update">Purchase order edited</option>
          <option value="po_order">Purchase order sent</option>
          <option value="po_receive">Delivery received</option>
          <option value="po_cancel">Purchase order cancelled</option>
          <option value="expense_create">Expense recorded</option>
          <option value="expense_void">Expense voided</option>
        </select>
      </div>
      <div>
        <label class="form-label" for="auditFrom">From</label>
        <input id="auditFrom" type="date" class="form-input" onchange="loadAuditLogs()">
      </div>
      <div>
        <label class="form-label" for="auditTo">To</label>
        <input id="auditTo" type="date" class="form-input" onchange="loadAuditLogs()">
      </div>
    </div>

    <div id="auditList" class="audit-list" aria-live="polite"><div class="admin-skeleton" aria-hidden="true"><span class="skeleton skeleton--block"></span><span class="skeleton skeleton--block"></span></div></div>
  </section>
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Audit Log'])
loadAuditLogs();
startPageRefresh(async () => { await loadAuditLogs(); renderStamp(); });
@endcomponent

@extends('layouts.admin')

@section('title', 'Approval Requests | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Approval Requests')

@section('page-actions')
<div class="page-head__actions">
        <button type="button" class="btn btn-secondary" onclick="loadApprovals()">Refresh</button>
      </div>
@endsection

@section('admin-content')
  <section id="s-approvals" class="admin-page">
    <header class="page-head">
      <div class="page-head__text">
        <h2 class="page-head__title">Approval requests</h2>
        <p class="page-head__purpose">Decide on price, stock, supplier and collection changes before they take effect.</p>
      </div>

    </header>

    <div id="approvalSummary" class="status-line" aria-label="approval summary"></div>

    <div class="filter-bar approval-filter-bar" role="group" aria-label="Filter approval requests">
      <div>
        <label class="form-label" for="approvalState">Status</label>
        <select id="approvalState" class="form-input" onchange="loadApprovals()">
          <option value="pending" selected>Waiting for a decision</option>
          <option value="">All statuses</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="cancelled">Withdrawn</option>
        </select>
      </div>
      <div>
        <label class="form-label" for="approvalType">Kind of change</label>
        <select id="approvalType" class="form-input" onchange="loadApprovals()">
          <option value="">Every kind</option>
          <option value="product.create">New till item</option>
          <option value="product.update">Price or item change</option>
          <option value="product.delete">Remove till item</option>
          <option value="recipe.update">Recipe change</option>
          <option value="inventory.create">New stock item</option>
          <option value="inventory.update">Stock item change</option>
          <option value="inventory.delete">Remove stock item</option>
          <option value="supplier.create">New supplier</option>
          <option value="supplier.update">Supplier change</option>
          <option value="supplier.delete">Remove supplier</option>
          <option value="settlement.create">Debt collection</option>
        </select>
      </div>
    </div>

    <div id="approvalList" class="approval-list" aria-live="polite"><div class="admin-skeleton" aria-hidden="true"><span class="skeleton skeleton--block"></span><span class="skeleton skeleton--block"></span></div></div>
  </section>
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Approvals'])
loadApprovals();
startPageRefresh(async () => { await loadApprovals(); renderSidebarBadges(); renderStamp(); });
@endcomponent

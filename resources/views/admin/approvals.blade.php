@extends('layouts.admin')

@section('title', 'Approval Requests | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Approval Requests')

@section('admin-content')
  <section id="s-approvals" class="admin-page">
    <div class="admin-page-titlebar">
      @include('partials.admin-page-header', ['eyebrow' => 'MAKER-CHECKER', 'heading' => 'Approval Requests', 'description' => 'Changes that need a decision before they take effect. Nothing on this list has touched your data yet.'])
    </div>

    <div id="approvalSummary" class="admin-summary" aria-label="approval summary"></div>

    <div class="card">
      <div class="approval-filters">
        <div class="admin-entry-field">
          <label class="form-label" for="approvalState">Status</label>
          <select id="approvalState" class="form-input" onchange="renderApprovals()">
            <option value="pending" selected>Waiting for a decision</option>
            <option value="">All statuses</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="cancelled">Withdrawn</option>
          </select>
        </div>
        <div class="admin-entry-field">
          <label class="form-label" for="approvalType">Kind of change</label>
          <select id="approvalType" class="form-input" onchange="renderApprovals()">
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
        <button type="button" class="btn btn-ghost btn-sm" onclick="loadApprovals()">Refresh</button>
      </div>
    </div>

    <div id="approvalList" class="approval-list" aria-live="polite"></div>
  </section>
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Approvals'])
loadApprovals();
startPageRefresh(async () => { await loadApprovals(); renderSidebarBadges(); renderStamp(); });
@endcomponent

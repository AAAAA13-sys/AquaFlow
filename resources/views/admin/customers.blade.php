@extends('layouts.admin')

@section('title', 'Customer Balances | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Customer Balances')

@section('admin-content')
  <section id="s-cust" class="admin-page">
    @include('partials.admin-page-header', ['eyebrow' => 'CUSTOMER ACCOUNTS', 'heading' => 'Customer Balances', 'description' => 'Review outstanding balances and open a customer account to record a payment.'])

    <div id="customerSummary" class="admin-summary" aria-label="customers summary"></div>
    <div class="filter-toolbar no-print table-filters">
      <div class="filter-group">
        <label class="form-label" for="custF">Balance status</label>
        <select id="custF" onchange="renderCustTable('', this.value)">
          <option value="">All customers</option>
          <option value="cash">Outstanding balance</option>
        </select>
      </div>
      @include('partials.table-filters', ['target' => 'custBody', 'label' => 'customers', 'refresh' => "renderCustTable('', document.getElementById('custF').value)", 'embedded' => true])
    </div>

    <div class="card data-table-wrapper">
      <table class="clean">
        <thead>
          <tr>
            <th>Customer</th>
            <th class="num">Visits</th>
            <th class="num">Cash owed</th>
            <th>Last visit</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="custBody"></tbody>
      </table>
    </div>
  </section>

  <!-- Lateral Detail Drawer -->
  @include('partials.editor-drawer', ['drawerId' => 'drawer', 'bodyId' => 'drawerBody', 'title' => 'Customer Detail', 'closeAction' => 'closeDrawer()'])
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Customer Balances'])
renderCustTable('', '');
@endcomponent

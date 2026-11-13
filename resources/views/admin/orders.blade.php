@extends('layouts.admin')
@section('title', 'Orders & Deliveries | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Orders & Deliveries')
@section('page-actions')
<div class="page-head__actions">
      <a class="btn btn-secondary" href="{{ url('/admin/staff-workspaces?mode=rider') }}">Cover a rider shift</a>
    </div>
@endsection

@section('admin-content')
<section id="s-orders" class="admin-page">
  <header class="page-head">
    <div class="page-head__text">
      <h2 class="page-head__title">Orders &amp; deliveries</h2>
      <p class="page-head__purpose">Follow each order from refilling to delivery, and see which riders still owe a cash remittance.</p>
    </div>

  </header>
@include('partials.fulfillment', ['monitor' => true])
</section>
@endsection
@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Orders & Deliveries'])
renderSidebarBadges();
@endcomponent

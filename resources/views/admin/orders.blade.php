@extends('layouts.admin')
@section('title', 'Orders & Deliveries | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Orders & Deliveries')
@section('admin-content')
<section class="admin-page">
@include('partials.admin-page-header', ['eyebrow' => 'DAILY OPERATIONS', 'heading' => 'Orders & deliveries', 'description' => 'Monitor preparation, delivery issues, and driver cash remittances.'])
@include('partials.fulfillment', ['monitor' => true])
</section>
@endsection
@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Orders & Deliveries'])
renderSidebarBadges();
@endcomponent

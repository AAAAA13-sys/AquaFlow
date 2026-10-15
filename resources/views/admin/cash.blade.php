@extends('layouts.admin')
@section('title', 'Cash Reconciliation | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Cash Reconciliation')
@section('admin-content')
<section class="admin-page">
  @include('partials.admin-page-header', ['eyebrow' => 'OPERATIONS', 'heading' => 'Cash Reconciliation', 'description' => 'Cash drawer tracking has been decommissioned. Cash collections and sales are recorded directly.'])
</section>
@endsection
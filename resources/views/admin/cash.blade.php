@extends('layouts.admin')
@section('title', 'Cash Drawers | AquaFlow')
@section('topbar-title', 'Cash Drawers')
@section('admin-content')
<section class="admin-page">
@include('partials.admin-page-header', ['eyebrow' => 'DAILY RECONCILIATION', 'heading' => 'Cash drawers', 'description' => 'Review starting cash, accepted collections and closing differences for each operator.'])
@include('partials.cash-drawer-overview')
</section>
@endsection
@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Cash Drawers'])
await renderCashShift();
startPageRefresh(renderCashShift);
@endcomponent

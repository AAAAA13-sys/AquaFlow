@extends('layouts.admin')
@section('title', 'Cash Drawers | AquaFlow')
@section('topbar-title', 'Cash Drawers')
@section('page-actions')
<div class="page-head__actions">
      <button class="btn btn-secondary" type="button" onclick="renderCashShift()">Refresh</button>
    </div>
@endsection

@section('admin-content')
<section id="s-cashdrawers" class="admin-page">
  <header class="page-head">
    <div class="page-head__text">
      <h2 class="page-head__title">Cash drawers</h2>
      <p class="page-head__purpose">Review starting cash, accepted collections and closing differences for each operator.</p>
    </div>

  </header>
@include('partials.cash-drawer-overview')
</section>
@endsection
@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Cash Drawers'])
await renderCashShift();
startPageRefresh(renderCashShift);
@endcomponent

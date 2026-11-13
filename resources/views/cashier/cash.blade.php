@extends('layouts.cashier')
@section('title', 'Cash Drawer | AquaFlow')
@section('topbar-title', 'Cash Drawer')
@section('page-actions')
<div class="page-head__actions">
    <button class="btn btn-secondary" type="button" onclick="renderCashShift()">Refresh</button>
  </div>
@endsection

@section('cashier-body')
<main id="main" tabindex="-1" class="admin-main-content cashier-page cashier-cash-page">
<header class="page-head">
  <div class="page-head__text">
    <h2 class="page-head__title">Cash drawer</h2>
    <p class="page-head__purpose">Record your starting cash, review accepted collections and count the drawer when the day is finished.</p>
  </div>

</header>
@include('partials.cash-drawer')
</main>
@endsection
@component('partials.page-startup', ['portal' => 'cashier', 'pageTitle' => 'Cash Drawer'])
await renderCashShift();
startPageRefresh(renderCashShift);
@endcomponent

@extends('layouts.cashier')
@section('title', 'Cash Drawer | AquaFlow')
@section('topbar-title', 'Cash Drawer')
@section('cashier-body')
<main class="admin-main-content cashier-page">
<header class="cashier-page-header"><div>
<p class="cashier-eyebrow">DAILY RECONCILIATION</p><h2>Cash drawer</h2>
<p>Record your starting cash, review accepted collections and count the drawer when the day is finished.</p>
</div></header>
@include('partials.cash-drawer', ['overview' => false])
</main>
@endsection
@component('partials.page-startup', ['portal' => 'cashier', 'pageTitle' => 'Cash Drawer'])
await renderCashShift();
startPageRefresh(renderCashShift);
@endcomponent

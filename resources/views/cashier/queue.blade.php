@extends('layouts.cashier')

@section('title', 'Orders in Progress | ' . ($stationName ?? 'AquaFlow'))
@section('topbar-title', 'Orders in Progress & Queue')

@section('cashier-body')
  <main class="admin-main-content">
    <div class="card">
      <div class="flex-between">
        <h3 class="panel-heading">Today&rsquo;s Queue</h3>
        <span id="queueCount" class="order-receipt-tag">0 orders</span>
      </div>
      <div id="queue" class="orders-queue-list queue-list-vertical"></div>
    </div>

    <div class="card order-type-selector">
      <h3 class="panel-heading">Stage Guide</h3>
      <div id="stageGuide" class="custody-summary-box"></div>
    </div>
  </main>
@endsection

@push('scripts')
  <script>
    (async () => {
      window.SESSION = await bootCashierPortal();
      if (SESSION) {
        renderQueuePage();
      }
    })();
  </script>
@endpush

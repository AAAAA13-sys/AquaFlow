@extends('layouts.admin')
@section('title', 'Cash Reconciliation | ' . ($stationName ?? 'AquaFlow'))
@section('meta-description', 'Open the drawer with a float and reconcile counted cash against recorded sales.')
@section('topbar-title', 'Cash Reconciliation')

@section('admin-content')
<section id="s-cash" class="admin-page">
  @include('partials.admin-page-header', ['eyebrow' => 'END OF DAY', 'heading' => 'Cash Reconciliation', 'description' => 'Open the drawer with the float you started with, then count the cash at close. The system works out what should be there and shows any difference.'])

  <div class="cash-grid">
    {{-- Step 1: open the drawer --}}
    <form class="card cash-card" id="cashOpenForm" onsubmit="event.preventDefault(); openCashDrawer()">
      <h3 class="panel-heading">Open the drawer</h3>
      <label class="form-label" for="cashFloat">Starting float</label>
      <input id="cashFloat" type="number" min="0" step="0.01" class="form-input" value="0" aria-describedby="floatHelp">
      <p class="settings-help" id="floatHelp">The cash you put in the drawer before the first sale.</p>
      <button type="submit" class="btn btn-primary" id="cashOpenBtn">Open drawer</button>
    </form>

    {{-- Step 2: count and close --}}
    <form class="card cash-card hidden" id="cashCloseForm" onsubmit="event.preventDefault(); closeCashDrawer()">
      <h3 class="panel-heading">Close the drawer</h3>
      <dl class="cash-summary" id="cashSummary"></dl>
      <label class="form-label" for="cashCounted">Counted cash</label>
      <input id="cashCounted" type="number" min="0" step="0.01" class="form-input" aria-describedby="countedHelp">
      <p class="settings-help" id="countedHelp">Total cash actually in the drawer, including the float.</p>
      <label class="form-label" for="cashNote">Note <span class="settings-help">(optional)</span></label>
      <input id="cashNote" maxlength="500" class="form-input" placeholder="Reason, if the drawer does not balance">
      <button type="submit" class="btn btn-primary" id="cashCloseBtn">Close and reconcile</button>
    </form>

    {{-- Result --}}
    <div class="card cash-card hidden" id="cashResultCard">
      <h3 class="panel-heading">Reconciliation result</h3>
      <div id="cashResult"></div>
    </div>
  </div>

  <div class="card data-table-wrapper">
    <h3 class="panel-heading">Recent reconciliations</h3>
    <table class="clean">
      <thead>
        <tr>
          <th>Date</th>
          <th>Cashier</th>
          <th class="num">Float</th>
          <th class="num">Cash sales</th>
          <th class="num">Expected</th>
          <th class="num">Counted</th>
          <th class="num">Difference</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody id="cashHistoryBody"></tbody>
    </table>
  </div>
</section>

@include('partials.editor-drawer', ['drawerId' => 'drawer', 'bodyId' => 'drawerBody', 'title' => 'Cash Detail', 'closeAction' => 'closeDrawer()'])
@endsection

@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Cash Reconciliation'])
renderCashShift();
@endcomponent
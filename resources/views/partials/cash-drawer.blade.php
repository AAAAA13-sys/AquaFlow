<section class="card" style="padding:24px" id="cashDrawerPanel" data-overview="{{ $overview ? '1' : '0' }}">
  <div class="flex-between"><h2 class="panel-heading">{{ $overview ? 'Cashier drawers' : 'Your cash drawer' }}</h2><button class="btn btn-secondary" type="button" onclick="renderCashShift()">Refresh</button></div>
  <p id="cashStatus" role="status">Loading drawer information…</p>
  @unless($overview)
  <form id="cashOpenForm" class="hidden" onsubmit="event.preventDefault(); openCashDrawer()">
    <label class="form-label" for="cashFloat">Starting cash</label><input id="cashFloat" class="form-input" type="number" min="0" max="1000000" step="0.01" required>
    <p class="settings-help">Cash before the first transaction today. Recorded cash sales and accepted collections are added automatically.</p>
    <button id="cashOpenBtn" class="btn btn-primary" type="submit">Open drawer</button>
  </form>
  <form id="cashCloseForm" class="hidden" onsubmit="event.preventDefault(); closeCashDrawer()">
    <div id="cashSummary"></div>
    <label class="form-label" for="cashCounted">Counted cash, including starting cash</label><input id="cashCounted" class="form-input" type="number" min="0" max="1000000" step="0.01" required>
    <label class="form-label" for="cashNote">Explanation (required for a shortage or overage)</label><textarea id="cashNote" class="form-input" maxlength="500"></textarea>
    <p class="settings-help">Resolve pending collections first. Closing blocks new sales and collections for today.</p>
    <button id="cashCloseBtn" class="btn btn-primary" type="submit">Count and close drawer</button>
  </form>
  <div id="cashResultCard" class="hidden"><h3>Closed drawer</h3><div id="cashResult"></div></div>
  @endunless
  @if($overview)<h3>Today's and outstanding drawers</h3><div id="cashOverview"></div>@endif
  <h3>Recent closed drawers</h3><div id="cashHistory"></div>
</section>
@push('scripts')
<script src="{{ asset('js/admin/cash.js') }}?v={{ asset_version('js/admin/cash.js') }}"></script>
@endpush
